<?php

declare(strict_types=1);

namespace App\Services\Statement;

use App\DTOs\StatementLineMatchEntry;
use App\Enums\StatementLineKind;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\CsvImport\ParsedTransactionDto;
use App\Services\RedbarkTransactionMatcher;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Matches statement lines to an account's own transactions on amount and a date within
 * three days. The description is ignored and so is the source, so a typed, CSV or bank-feed
 * row can all be matched, including ones with no description. Each transaction is claimed by
 * at most one line. A transaction another statement reconciled can still match, so an
 * overlapping statement does not publish the overlap twice.
 */
final class StatementLineMatcher
{
    /**
     * Lines on the same day are matched first, then each remaining line in date order takes
     * the earliest-dated transaction still inside its window, which keeps a run of lines
     * that sit one day off from stealing each other's transactions.
     *
     * @param  Collection<int, ParsedTransactionDto>  $lines
     * @return list<StatementLineMatchEntry>
     */
    public function match(Account $account, Collection $lines, CarbonPeriod $period): array
    {
        $start = CarbonImmutable::instance($period->getStartDate())->startOfDay();
        $end = CarbonImmutable::instance($period->getEndDate())->startOfDay();
        $tolerance = RedbarkTransactionMatcher::DATE_TOLERANCE_DAYS;

        $candidates = Transaction::query()
            ->where('user_id', $account->user_id)
            ->where('account_id', $account->id)
            ->current()
            ->whereNull('split_parent_id')
            ->whereBetween('post_date', [$start->subDays($tolerance)->toDateString(), $end->addDays($tolerance)->toDateString()])
            ->orderBy('post_date')
            ->orderBy('id')
            ->get();

        $lines = $lines->values();
        $order = $lines->keys()->sortBy(fn (int $key): string => $lines[$key]->postDate->toDateString().'-'.mb_str_pad((string) $key, 8, '0', STR_PAD_LEFT))->values();

        /** @var array<int, true> $claimed */
        $claimed = [];
        /** @var array<int, Transaction> $matches */
        $matches = [];

        foreach ([0, $tolerance] as $maxDays) {
            foreach ($order as $key) {
                if (isset($matches[$key])) {
                    continue;
                }

                $line = $lines[$key];
                $match = $candidates->first(fn (Transaction $candidate): bool => ! isset($claimed[$candidate->id])
                    && $candidate->amount === $line->amount
                    && $this->daysApart($candidate, $line->postDate) <= ($maxDays === 0 ? 0 : $tolerance));

                if ($match !== null) {
                    $claimed[$match->id] = true;
                    $matches[$key] = $match;
                }
            }
        }

        $entries = [];

        foreach ($lines as $key => $line) {
            $entries[] = new StatementLineMatchEntry(isset($matches[$key]) ? StatementLineKind::Matched : StatementLineKind::StatementOnly, $line, $matches[$key] ?? null);
        }

        foreach ($candidates as $candidate) {
            $day = CarbonImmutable::parse($candidate->post_date)->startOfDay();

            if (! isset($claimed[$candidate->id]) && $day->betweenIncluded($start, $end)) {
                $entries[] = new StatementLineMatchEntry(StatementLineKind::FeedOnly, null, $candidate);
            }
        }

        return $entries;
    }

    private function daysApart(Transaction $candidate, CarbonImmutable $date): int
    {
        return (int) abs(CarbonImmutable::parse($candidate->post_date)->startOfDay()->diffInDays($date));
    }
}
