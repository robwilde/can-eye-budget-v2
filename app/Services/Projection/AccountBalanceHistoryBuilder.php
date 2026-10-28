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
     * Rebuilds, for each account the transactions sit on, from the earliest post date among
     * them. Pass every version a write touched (the row before and after an edit, a deleted
     * row) so the earliest affected day is covered; the live parent version a deleted row
     * hands current status back to is added here. Accounts are rebuilt in id order inside
     * the caller's DB transaction.
     */
    public function rebuildForTransactions(Transaction ...$transactions): void
    {
        $earliest = [];

        foreach ($this->withRestoredParents($transactions) as $transaction) {
            $key = $transaction->account_id;
            $day = CarbonImmutable::parse($transaction->post_date)->startOfDay();

            if (! isset($earliest[$key]) || $day->lessThan($earliest[$key]['from'])) {
                $earliest[$key] = ['userId' => $transaction->user_id, 'from' => $day];
            }
        }

        ksort($earliest);

        Account::query()->getConnection()->transaction(function () use ($earliest): void {
            foreach ($earliest as $accountId => ['userId' => $userId, 'from' => $from]) {
                $account = Account::query()->where('user_id', $userId)->find($accountId);

                if ($account instanceof Account) {
                    $this->rebuild($account, $from);
                }
            }
        });
    }

    /**
     * Rebuilds after an edit that produced a new version, but only when the edit moved
     * money: a change of account, amount, direction, post date or status. A change to the
     * description, notes, category or merchant leaves the history alone.
     */
    public function rebuildForEdit(Transaction $before, Transaction $after): void
    {
        $this->rebuildForEdits([$before, $after]);
    }

    /**
     * One rebuild for several edited rows (the two legs of a transfer): the pairs that moved
     * money are rebuilt together, so each account is locked once, in id order, from its
     * earliest affected day.
     *
     * @param  array{Transaction, Transaction}  ...$edits  [before, after] pairs
     */
    public function rebuildForEdits(array ...$edits): void
    {
        $touched = [];

        foreach ($edits as [$before, $after]) {
            if ($this->movesBalance($before) !== $this->movesBalance($after)) {
                array_push($touched, $before, $after);
            }
        }

        if ($touched !== []) {
            $this->rebuildForTransactions(...$touched);
        }
    }

    /**
     * Takes the account rows' write locks, in id order. Call it first in any transaction that
     * inserts transactions and then rebuilds: on MariaDB the foreign key makes each insert
     * hold a shared lock on the account row, so two such writers that insert first and only
     * then ask for the exclusive lock rebuild() needs deadlock each other.
     */
    public function lockAccounts(?int ...$accountIds): void
    {
        $ids = array_values(array_unique(array_filter($accountIds)));

        if ($ids === []) {
            return;
        }

        sort($ids);

        Account::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    /**
     * Deleting a revision makes its undeleted parent the current version again, so the
     * parent's own account and date are affected too.
     *
     * @param  array<int, Transaction>  $transactions
     * @return list<Transaction>
     */
    private function withRestoredParents(array $transactions): array
    {
        $deleted = collect($transactions)
            ->filter(fn (Transaction $transaction): bool => $transaction->trashed() && $transaction->parent_transaction_id !== null);

        if ($deleted->isEmpty()) {
            return array_values($transactions);
        }

        $parents = Transaction::query()
            ->whereIn('user_id', $deleted->pluck('user_id')->unique()->values())
            ->whereIn('id', $deleted->pluck('parent_transaction_id')->unique()->values())
            ->get();

        return [...array_values($transactions), ...$parents->all()];
    }

    /** @return array{int, int, string, string, string} */
    private function movesBalance(Transaction $transaction): array
    {
        return [
            $transaction->account_id,
            $transaction->amount,
            $transaction->direction->value,
            $transaction->status->value,
            CarbonImmutable::parse($transaction->post_date)->toDateString(),
        ];
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
     * Amounts count by magnitude: the direction decides the sign, because bank-feed and CSV
     * rows store debits as negative cents while manual entries store them positive.
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
            ->selectRaw("coalesce(sum(case when direction = 'credit' then abs(amount) else 0 end), 0) as inflows")
            ->selectRaw("coalesce(sum(case when direction = 'debit' then abs(amount) else 0 end), 0) as outflows")
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
