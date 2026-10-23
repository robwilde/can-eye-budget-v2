<?php

declare(strict_types=1);

namespace App\Services\Projection;

use App\Enums\TransactionStatus;
use App\Enums\ValuationKind;
use App\Models\Account;
use App\Models\AccountDailyBalance;
use App\Models\AccountValuation;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds an account's daily cash balance rows, one per day from its first entry to today.
 *
 * The first day starts from 0 when the account has an opening balance (which then sets that
 * day's end). Without one, it works backwards: from the earliest valuation if there is one,
 * otherwise from the stored accounts.balance as of today. Each day adds its posted, current,
 * non-deleted transactions; a valuation on a day resets that day's end balance and the gap is
 * recorded as the day's adjustment.
 */
final class AccountBalanceHistoryBuilder
{
    private const int INSERT_CHUNK = 500;

    /**
     * Recomputes from $from forward, or the whole history when $from is null. A partial
     * rebuild keeps earlier rows only when a valuation dated before $from pins them; without
     * one the earlier days are worked out backwards from a later anchor, so any change can
     * move them and the whole history is rebuilt. Runs in the caller's DB transaction.
     */
    public function rebuild(Account $account, ?CarbonImmutable $from = null): void
    {
        DB::transaction(function () use ($account, $from): void {
            $account = Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $today = CarbonImmutable::today();
            $flows = $this->dailyFlows($account);

            /** @var array<string, AccountValuation> $valuations */
            $valuations = $account->valuations()->orderBy('valued_on')->get()
                ->keyBy(fn (AccountValuation $valuation): string => $valuation->valued_on->toDateString())
                ->all();

            [$firstDay, $startBalance] = $this->startingPoint($account, $flows, $valuations, $today);

            $resumeFrom = $from?->startOfDay();
            $pinned = $resumeFrom !== null
                && $resumeFrom->greaterThan($firstDay)
                && $account->valuations()->whereDate('valued_on', '<', $resumeFrom)->exists();
            $previous = $pinned
                ? $account->dailyBalances()->whereDate('balance_date', $resumeFrom->subDay())->first()
                : null;

            if ($previous !== null) {
                $firstDay = $resumeFrom;
                $startBalance = $previous->end_balance;
                $account->dailyBalances()->whereDate('balance_date', '>=', $firstDay)->delete();
            } else {
                $account->dailyBalances()->delete();
            }

            $this->writeRows($account, $firstDay, $today, $startBalance, $flows, $valuations);
        });
    }

    /**
     * @param  array<string, array{inflows: int, outflows: int}>  $flows
     * @param  array<string, AccountValuation>  $valuations
     * @return array{0: CarbonImmutable, 1: int}
     */
    private function startingPoint(Account $account, array $flows, array $valuations, CarbonImmutable $today): array
    {
        foreach ($valuations as $valuation) {
            if ($valuation->kind === ValuationKind::OpeningBalance) {
                return [$valuation->valued_on, 0];
            }
        }

        $dates = [...array_keys($flows), ...array_keys($valuations)];
        $firstDay = $dates === [] ? $today : CarbonImmutable::parse(min($dates));

        if ($firstDay->greaterThan($today)) {
            $firstDay = $today;
        }

        $earliest = $valuations === [] ? null : reset($valuations);
        $anchorDay = $earliest->valued_on ?? $today;
        $anchorBalance = $earliest->balance ?? $account->balance;

        return [$firstDay, $anchorBalance - $this->netBetween($flows, $firstDay, $anchorDay)];
    }

    /**
     * @param  array<string, array{inflows: int, outflows: int}>  $flows
     * @param  array<string, AccountValuation>  $valuations
     */
    private function writeRows(Account $account, CarbonImmutable $firstDay, CarbonImmutable $lastDay, int $startBalance, array $flows, array $valuations): void
    {
        $rows = [];
        $balance = $startBalance;
        $now = CarbonImmutable::now();

        for ($day = $firstDay; $day->lessThanOrEqualTo($lastDay); $day = $day->addDay()) {
            $key = $day->toDateString();
            $inflows = $flows[$key]['inflows'] ?? 0;
            $outflows = $flows[$key]['outflows'] ?? 0;
            $start = $balance;
            $end = $start + $inflows - $outflows;
            $adjustment = 0;

            if (isset($valuations[$key])) {
                $adjustment = $valuations[$key]->balance - $end;
                $end = $valuations[$key]->balance;
            }

            $rows[] = [
                'account_id' => $account->id,
                'balance_date' => $key,
                'start_balance' => $start,
                'end_balance' => $end,
                'inflows' => $inflows,
                'outflows' => $outflows,
                'adjustments' => $adjustment,
                'currency' => $account->currency,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $balance = $end;

            if (count($rows) === self::INSERT_CHUNK) {
                AccountDailyBalance::query()->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            AccountDailyBalance::query()->insert($rows);
        }
    }

    /**
     * Credits and debits per day of the account's posted, current, non-deleted transactions.
     *
     * @return array<string, array{inflows: int, outflows: int}>
     */
    private function dailyFlows(Account $account): array
    {
        $flows = [];

        $rows = Transaction::query()
            ->current()
            ->where('account_id', $account->id)
            ->where('status', TransactionStatus::Posted)
            ->toBase()
            ->selectRaw('date(post_date) as day')
            ->selectRaw("coalesce(sum(case when direction = 'credit' then amount else 0 end), 0) as inflows")
            ->selectRaw("coalesce(sum(case when direction = 'debit' then amount else 0 end), 0) as outflows")
            ->groupByRaw('date(post_date)')
            ->get();

        foreach ($rows as $row) {
            $flows[(string) $row->day] = ['inflows' => (int) $row->inflows, 'outflows' => (int) $row->outflows];
        }

        return $flows;
    }

    /**
     * Net flow of the days from $from to $until, both inclusive.
     *
     * @param  array<string, array{inflows: int, outflows: int}>  $flows
     */
    private function netBetween(array $flows, CarbonImmutable $from, CarbonImmutable $until): int
    {
        $net = 0;
        $first = $from->toDateString();
        $last = $until->toDateString();

        foreach ($flows as $day => $flow) {
            if ($day >= $first && $day <= $last) {
                $net += $flow['inflows'] - $flow['outflows'];
            }
        }

        return $net;
    }
}
