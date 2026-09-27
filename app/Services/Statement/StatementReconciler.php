<?php

declare(strict_types=1);

namespace App\Services\Statement;

use App\Enums\StatementLineKind;
use App\Enums\StatementLineResolution;
use App\Enums\StatementReconciliationStatus;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Exceptions\Statement\StatementFileUnreadable;
use App\Exceptions\Statement\StatementLineAlreadyFolded;
use App\Exceptions\Statement\StatementLineNotResolvable;
use App\Exceptions\Statement\StatementReconciliationClosed;
use App\Exceptions\Statement\StatementReconciliationIncomplete;
use App\Models\StatementReconciliation;
use App\Models\StatementReconciliationLine;
use App\Models\Transaction;
use App\Services\CsvImport\CsvParserService;
use App\Services\CsvImport\ParsedTransactionDto;
use App\Services\RedbarkTransactionMatcher;
use App\Services\TransactionIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Checks one account's statement CSV for one calendar month against the transactions
 * the feed delivered, one-to-one: each statement line claims at most one transaction
 * and each transaction is claimed by at most one line.
 *
 * Matching reuses RedbarkTransactionMatcher's rule (exact amount, date within the
 * tolerance, equal fingerprint) but works in memory against candidates loaded once,
 * and in the opposite direction: statement lines looking for feed rows.
 */
final readonly class StatementReconciler
{
    public function __construct(
        private CsvParserService $parser,
        private TransactionIngestor $ingestor,
    ) {}

    public function build(StatementReconciliation $reconciliation): void
    {
        $this->assertOpen($reconciliation);

        $path = Storage::disk('local')->path($reconciliation->stored_path);
        $mapping = $reconciliation->column_mapping;

        // Every row must parse before anything is replaced: a skipped row would let the
        // month close without that statement line ever being checked.
        try {
            $summary = $this->parser->summarize($path, $mapping);
        } catch (Throwable $e) {
            throw new StatementFileUnreadable('The statement could not be read: '.$e->getMessage(), previous: $e);
        }

        if ($summary->errorRows !== []) {
            $first = $summary->errorRows[0];

            throw new StatementFileUnreadable(sprintf(
                '%d statement row(s) could not be read with this column mapping (first: row %d, %s). Check the mapping and try again.',
                count($summary->errorRows),
                $first['row'],
                $first['error'],
            ));
        }

        /** @var list<ParsedTransactionDto> $rows */
        $rows = iterator_to_array($this->parser->eachRow($path, $mapping), false);

        DB::transaction(function () use ($reconciliation, $rows, $summary): void {
            $periodStart = $reconciliation->period_start->startOfDay();
            $periodEnd = $reconciliation->period_end->startOfDay();

            [$statementSnapshots, $feedSnapshots] = $this->snapshot($reconciliation);
            $reconciliation->lines()->delete();

            $candidates = Transaction::query()
                ->where('account_id', $reconciliation->account_id)
                // Only rows that came from the bank count as delivered: feed rows, plus CSV
                // rows (earlier statement imports, including lines resolved here by import).
                // A hand-entered row must not stand in for a feed row that never arrived.
                ->whereIn('source', [TransactionSource::Redbark, TransactionSource::Csv])
                ->whereNull('deleted_at')
                ->whereBetween('post_date', [
                    $periodStart->subDays(RedbarkTransactionMatcher::DATE_TOLERANCE_DAYS)->toDateString(),
                    $periodEnd->addDays(RedbarkTransactionMatcher::DATE_TOLERANCE_DAYS)->toDateString(),
                ])
                ->orderBy('id')
                ->get()
                ->keyBy('id');

            /** @var array<int, true> $claimed */
            $claimed = [];
            $outside = 0;
            $debits = 0;
            $credits = 0;

            foreach ($rows as $row) {
                if ($row->postDate->lessThan($periodStart) || $row->postDate->greaterThan($periodEnd)) {
                    $outside++;

                    continue;
                }

                if ($row->amount < 0) {
                    $debits += abs($row->amount);
                } else {
                    $credits += $row->amount;
                }

                $previous = isset($statementSnapshots[$row->csvHash])
                    ? array_shift($statementSnapshots[$row->csvHash])
                    : null;
                $match = $this->carriedMatch($previous, $candidates, $claimed)
                    ?? $this->findMatch($row, $candidates, $claimed);

                if ($match !== null) {
                    $claimed[$match->id] = true;
                }

                $kind = $match !== null ? StatementLineKind::Matched : StatementLineKind::StatementOnly;

                $reconciliation->lines()->create([
                    'kind' => $kind,
                    'transaction_id' => $match?->id,
                    'csv_hash' => $row->csvHash,
                    'post_date' => $row->postDate,
                    'amount' => $row->amount,
                    'description' => mb_substr($row->description, 0, 255),
                    ...$this->carriedFields($previous, $kind),
                ]);
            }

            foreach ($candidates as $candidate) {
                if (isset($claimed[$candidate->id])) {
                    continue;
                }

                $postDate = CarbonImmutable::parse($candidate->post_date)->startOfDay();

                if ($postDate->lessThan($periodStart) || $postDate->greaterThan($periodEnd)) {
                    continue;
                }

                $reconciliation->lines()->create([
                    'kind' => StatementLineKind::FeedOnly,
                    'transaction_id' => $candidate->id,
                    'csv_hash' => null,
                    'post_date' => $postDate,
                    'amount' => $candidate->amount,
                    'description' => mb_substr((string) $candidate->description, 0, 255),
                    'checked_at' => $feedSnapshots[$candidate->id] ?? null,
                ]);
            }

            $reconciliation->update([
                'lines_outside_period' => $outside,
                'statement_debit_total' => $debits,
                'statement_credit_total' => $credits,
                'closing_balance' => $summary->closingBalance,
            ]);
        });
    }

    public function resolveImport(StatementReconciliationLine $line): Transaction
    {
        $reconciliation = $line->reconciliation;
        $this->assertOpen($reconciliation);

        if ($line->kind !== StatementLineKind::StatementOnly) {
            throw new StatementLineNotResolvable('Only a statement-only line can be imported.');
        }

        return DB::transaction(function () use ($line, $reconciliation): Transaction {
            $values = [
                'user_id' => $reconciliation->user_id,
                'amount' => $line->amount,
                'direction' => $line->amount < 0 ? TransactionDirection::Debit : TransactionDirection::Credit,
                'description' => $line->description,
                'post_date' => $line->post_date,
                'transaction_date' => $line->post_date,
                'status' => TransactionStatus::Posted,
                'source' => TransactionSource::Csv,
            ];

            $existing = Transaction::withTrashed()
                ->where('account_id', $reconciliation->account_id)
                ->where('csv_hash', $line->csv_hash)
                ->first();

            if ($existing?->trashed()) {
                if ($existing->folded_into_transaction_id !== null) {
                    throw new StatementLineAlreadyFolded('This statement line was folded into its parent transaction.');
                }

                $existing->fill($values);
                $existing->restore();
                $transaction = $existing;
            } elseif ($existing !== null) {
                // A live row with this hash the matcher could not pair (e.g. a blank
                // description has no fingerprint): reuse it, as a CSV re-import would.
                $existing->fill($values)->save();
                $transaction = $existing;
            } else {
                $transaction = $this->ingestor->ingest(new Transaction([
                    'account_id' => $reconciliation->account_id,
                    'csv_hash' => $line->csv_hash,
                    ...$values,
                ]));
            }

            $line->update([
                'kind' => StatementLineKind::Matched,
                'transaction_id' => $transaction->id,
                'resolution' => StatementLineResolution::Imported,
                'checked_at' => now(),
            ]);

            return $transaction;
        });
    }

    public function resolveLink(StatementReconciliationLine $line, StatementReconciliationLine $feedOnly): void
    {
        $this->assertOpen($line->reconciliation);

        if ($line->statement_reconciliation_id !== $feedOnly->statement_reconciliation_id) {
            throw new StatementLineNotResolvable('Both lines must belong to the same reconciliation.');
        }

        if ($line->kind !== StatementLineKind::StatementOnly || $feedOnly->kind !== StatementLineKind::FeedOnly) {
            throw new StatementLineNotResolvable('A link pairs a statement-only line with a feed-only line.');
        }

        // transaction_id is nullOnDelete: a feed row deleted since build() leaves nothing to link.
        // Transactions soft-delete, so a feed row the user deleted since build() still has an id.
        if ($feedOnly->transaction_id === null || ! Transaction::query()->whereKey($feedOnly->transaction_id)->exists()) {
            throw new StatementLineNotResolvable('The feed transaction behind this line no longer exists.');
        }

        DB::transaction(function () use ($line, $feedOnly): void {
            $line->update([
                'kind' => StatementLineKind::Matched,
                'transaction_id' => $feedOnly->transaction_id,
                'resolution' => StatementLineResolution::Linked,
                'checked_at' => now(),
            ]);

            $feedOnly->delete();
        });
    }

    public function resolveIgnore(StatementReconciliationLine $line, string $note): void
    {
        $this->assertOpen($line->reconciliation);

        if ($line->kind === StatementLineKind::Matched) {
            throw new StatementLineNotResolvable('A matched line has no discrepancy to ignore.');
        }

        if ($line->kind === StatementLineKind::FeedOnly) {
            throw new StatementLineNotResolvable('A feed-only line is acknowledged by ticking it, not ignored.');
        }

        $line->update([
            'resolution' => StatementLineResolution::Ignored,
            'note' => $note,
            'checked_at' => now(),
        ]);
    }

    public function close(StatementReconciliation $reconciliation): void
    {
        $this->assertOpen($reconciliation);

        if (! $reconciliation->canClose()) {
            throw new StatementReconciliationIncomplete('Every line must be checked before the reconciliation can close.');
        }

        $reconciliation->update([
            'status' => StatementReconciliationStatus::Closed,
            'closed_at' => now(),
        ]);
    }

    public function reopen(StatementReconciliation $reconciliation): void
    {
        $reconciliation->update([
            'status' => StatementReconciliationStatus::Open,
            'closed_at' => null,
        ]);
    }

    private static function pendingRank(Transaction $transaction): int
    {
        return $transaction->status === TransactionStatus::Pending ? 1 : 0;
    }

    private function assertOpen(StatementReconciliation $reconciliation): void
    {
        if (! $reconciliation->isOpen()) {
            throw new StatementReconciliationClosed('This reconciliation is closed; reopen it to make changes.');
        }
    }

    /**
     * Existing lines, so a re-upload carries the user's work forward: statement lines
     * queued per csv_hash (consumed in file order, since identical rows share a hash),
     * and feed-only check marks keyed by transaction id.
     *
     * @return array{0: array<string, list<StatementReconciliationLine>>, 1: array<int, CarbonImmutable>}
     */
    private function snapshot(StatementReconciliation $reconciliation): array
    {
        $statement = [];
        $feed = [];

        foreach ($reconciliation->lines()->orderBy('id')->get() as $line) {
            if ($line->kind === StatementLineKind::FeedOnly) {
                if ($line->transaction_id !== null && $line->checked_at !== null) {
                    $feed[$line->transaction_id] = $line->checked_at;
                }

                continue;
            }

            if ($line->csv_hash !== null) {
                $statement[$line->csv_hash][] = $line;
            }
        }

        return [$statement, $feed];
    }

    /**
     * A previously matched line keeps its transaction (e.g. one the user linked by hand
     * whose description would not fingerprint-match) while that row is still available.
     *
     * @param  Collection<int, Transaction>  $candidates
     * @param  array<int, true>  $claimed
     */
    private function carriedMatch(?StatementReconciliationLine $previous, Collection $candidates, array $claimed): ?Transaction
    {
        if ($previous?->kind !== StatementLineKind::Matched || $previous->transaction_id === null) {
            return null;
        }

        if (isset($claimed[$previous->transaction_id])) {
            return null;
        }

        return $candidates->get($previous->transaction_id);
    }

    /**
     * @param  Collection<int, Transaction>  $candidates
     * @param  array<int, true>  $claimed
     */
    private function findMatch(ParsedTransactionDto $row, Collection $candidates, array $claimed): ?Transaction
    {
        $fingerprint = RedbarkTransactionMatcher::fingerprint($row->description);

        if ($fingerprint === '') {
            return null;
        }

        return $candidates
            ->filter(fn (Transaction $candidate): bool => ! isset($claimed[$candidate->id])
                && $candidate->amount === $row->amount
                && $this->daysApart($candidate, $row->postDate) <= RedbarkTransactionMatcher::DATE_TOLERANCE_DAYS
                && RedbarkTransactionMatcher::fingerprint($candidate->description) === $fingerprint)
            ->sortBy([
                fn (Transaction $a, Transaction $b): int => $this->daysApart($a, $row->postDate) <=> $this->daysApart($b, $row->postDate),
                fn (Transaction $a, Transaction $b): int => self::pendingRank($a) <=> self::pendingRank($b),
                fn (Transaction $a, Transaction $b): int => $a->id <=> $b->id,
            ])
            ->first();
    }

    private function daysApart(Transaction $candidate, CarbonImmutable $date): int
    {
        return (int) abs(CarbonImmutable::parse($candidate->post_date)->startOfDay()->diffInDays($date));
    }

    /**
     * The user's work on the previous version of this line survives only when it still
     * describes the same outcome: a resolved statement-only line that is still
     * statement-only, or a checked match that still matches.
     *
     * @return array<string, mixed>
     */
    private function carriedFields(?StatementReconciliationLine $previous, StatementLineKind $kind): array
    {
        if ($previous === null || $previous->kind !== $kind) {
            return [];
        }

        $carry = match ($kind) {
            StatementLineKind::StatementOnly => $previous->resolution !== null,
            StatementLineKind::Matched => $previous->checked_at !== null,
            StatementLineKind::FeedOnly => false,
        };

        if (! $carry) {
            return [];
        }

        return [
            'checked_at' => $previous->checked_at,
            'resolution' => $previous->resolution,
            'note' => $previous->note,
        ];
    }
}
