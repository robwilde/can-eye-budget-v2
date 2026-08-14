<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Matches an incoming Redbark row against a transaction the user already has, so a feed
 * connected to an account with CSV history adopts that history instead of duplicating it.
 *
 * `redbark_id` alone is not enough: it only recognises rows this feed created. A CSV import
 * of the same statement carries a csv_hash and no redbark_id, so without this matcher every
 * overlapping row is imported a second time.
 *
 * The CSV importer's own key — sha256(date|cents|lowercased description) — cannot be reused,
 * because the bank feed masks account digits that the CSV spells out:
 *
 *     CSV     "Direct Debit NIB - 64699390"
 *     Redbark "Direct Debit NIB - xxxx9390"
 *
 * Collapsing every digit and mask run to a single token makes those identical, which is what
 * `fingerprint()` does. Measured against 449 real overlapping rows, fingerprint + exact amount
 * matched all of them; amount and date alone, without a fingerprint match, added nothing — so
 * a fingerprint match is required and there is no looser fallback that could merge two
 * genuinely distinct transactions.
 */
final readonly class RedbarkTransactionMatcher
{
    /** Statement and feed dates for the same purchase can sit a day or two apart. */
    public const int DATE_TOLERANCE_DAYS = 3;

    /**
     * A description reduced to its stable shape: lower-cased, with every run of digits or
     * mask characters collapsed to a single `#`.
     */
    public static function fingerprint(?string $description): string
    {
        $value = mb_strtolower(mb_trim((string) $description));

        foreach (['/x{2,}/', '/\d+/', '/#+/'] as $pattern) {
            $value = (string) preg_replace($pattern, '#', $value);
        }

        return mb_trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /**
     * The transaction this row already exists as, or null when it is genuinely new.
     *
     * Soft-deleted rows are included on purpose: an international fee that
     * TransactionFeeFolder folded into its parent purchase lives on as a trashed row, and the
     * caller must recognise it rather than resurrect the fee the parent already absorbs.
     *
     * @param  list<int>  $alreadyClaimed  ids claimed earlier in this run, so the match is one-to-one
     */
    public function findExisting(
        int $accountId,
        int $amountCents,
        CarbonImmutable $postDate,
        ?string $description,
        array $alreadyClaimed = [],
    ): ?Transaction {
        $fingerprint = self::fingerprint($description);

        if ($fingerprint === '') {
            return null;
        }

        return Transaction::withTrashed()
            ->where('account_id', $accountId)
            ->whereNull('redbark_id')
            ->where('amount', $amountCents)
            ->whereBetween('post_date', [
                $postDate->subDays(self::DATE_TOLERANCE_DAYS)->toDateString(),
                $postDate->addDays(self::DATE_TOLERANCE_DAYS)->toDateString(),
            ])
            ->when($alreadyClaimed !== [], fn (Builder $query): Builder => $query->whereNotIn('id', $alreadyClaimed))
            ->with('children')
            ->get()
            ->filter(fn (Transaction $candidate): bool => self::fingerprint($candidate->description) === $fingerprint)
            // Prefer the same day, then a version the user has not superseded. Superseded
            // rows are ranked last rather than excluded: a version chain can span accounts,
            // so filtering them out silently orphans the row this feed is looking at.
            ->sortBy([
                fn (Transaction $a, Transaction $b): int => abs((int) $a->post_date->diffInDays($postDate))
                    <=> abs((int) $b->post_date->diffInDays($postDate)),
                fn (Transaction $a, Transaction $b): int => self::isSuperseded($a) <=> self::isSuperseded($b),
            ])
            ->first();
    }

    private static function isSuperseded(Transaction $transaction): int
    {
        return $transaction->children->whereNull('deleted_at')->isNotEmpty() ? 1 : 0;
    }
}
