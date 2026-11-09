<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\BalanceSheet;
use App\DTOs\BalanceSheetGroup;
use App\Enums\AccountClass;
use App\Enums\AccountOrder;
use App\Models\Account;
use App\Models\AccountDailyBalance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Net worth is the signed sum of the user's account balances, debt being stored negative.
 * AccountClass::isAsset only groups the balance sheet. The accounts counted are the ones the
 * dashboard totals use: the user's own, tracked, active and not excluded from reports.
 */
final class NetWorthService
{
    public function current(User $user): int
    {
        return array_sum($this->balancesInBase($user, $this->countedAccounts($user)->get()));
    }

    public function balanceSheet(User $user): BalanceSheet
    {
        $accounts = $this->countedAccounts($user)->get();
        $balances = $this->balancesInBase($user, $accounts);
        $order = $user->preferences->accountOrder;

        $assets = $this->groups($accounts->reject(fn (Account $account): bool => $account->isLiability()), $order, $balances);
        $liabilities = $this->groups($accounts->filter(fn (Account $account): bool => $account->isLiability()), $order, $balances);

        return new BalanceSheet(
            assets: $assets,
            liabilities: $liabilities,
            assetTotal: array_sum(array_map(fn (BalanceSheetGroup $group): int => $group->total, $assets)),
            liabilityTotal: array_sum(array_map(fn (BalanceSheetGroup $group): int => $group->total, $liabilities)),
        );
    }

    /**
     * Net worth for each day from $from to $to inclusive, keyed by Y-m-d. An account that was
     * disabled counts through the day before it was disabled and not from that day on.
     *
     * @return array<string, int>
     */
    public function series(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $accounts = $this->countedAccounts($user, includeDisabled: true)->get(['id', 'disabled_at', 'currency']);
        $balances = $this->dailyBalances($accounts->pluck('id')->all(), $from, $to);
        $converter = CurrencyConverter::enabled() ? app(CurrencyConverter::class) : null;

        $series = [];

        foreach ($this->days($from, $to) as $day) {
            $total = 0;
            $date = CarbonImmutable::parse($day);

            foreach ($accounts as $account) {
                if ($account->disabled_at === null || $day < $account->disabled_at->toDateString()) {
                    $balance = $balances[$account->id][$day];
                    $total += $converter === null ? $balance : $converter->convert($balance, $account->currency, $user->base_currency, $date);
                }
            }

            $series[$day] = $total;
        }

        return $series;
    }

    /**
     * Each of the user's given accounts' end-of-day balance for every day from $from to $to,
     * keyed by account id then Y-m-d. Ids that are not the user's are ignored.
     *
     * @param  list<int>  $accountIds
     * @return array<int, array<string, int>>
     */
    public function accountSeries(User $user, array $accountIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ownedIds = $user->accounts()->whereIn('id', $accountIds)->pluck('id')->all();

        return $this->dailyBalances($ownedIds, $from, $to);
    }

    /**
     * A trend is favourable when assets rise and liabilities fall. Debt is stored negative, so
     * a falling debt is a rising balance and one signed comparison covers both.
     *
     * @param  array<string, int>  $series
     */
    public function isFavourable(array $series): bool
    {
        return $series === [] || end($series) >= reset($series);
    }

    /**
     * @return Builder<Account>
     */
    private function countedAccounts(User $user, bool $includeDisabled = false): Builder
    {
        $query = Account::query()
            ->where('user_id', $user->id)
            ->tracked()
            ->inReports();

        if (! $includeDisabled) {
            return $query->active();
        }

        return $query->where(function (Builder $accounts): void {
            $accounts->active()->orWhereNotNull('disabled_at');
        });
    }

    /**
     * Each account's balance in cents: as stored, or with budget.multi_currency on converted
     * into the user's base currency at today's rate.
     *
     * @param  Collection<int, Account>  $accounts
     * @return array<int, int>
     */
    private function balancesInBase(User $user, Collection $accounts): array
    {
        $converter = CurrencyConverter::enabled() ? app(CurrencyConverter::class) : null;
        $today = CarbonImmutable::today();
        $balances = [];

        foreach ($accounts as $account) {
            $balances[$account->id] = $converter === null
                ? $account->balance
                : $converter->convert($account->balance, $account->currency, $user->base_currency, $today);
        }

        return $balances;
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  array<int, int>  $balances  each account's balance in the user's base currency
     * @return list<BalanceSheetGroup>
     */
    private function groups(Collection $accounts, AccountOrder $order, array $balances): array
    {
        $groups = [];

        foreach (AccountClass::cases() as $class) {
            $inClass = $accounts->filter(fn (Account $account): bool => $account->type === $class);

            if ($inClass->isEmpty()) {
                continue;
            }

            $sorted = $order->sortsByBalance()
                ? $inClass->sort(function (Account $a, Account $b) use ($balances, $order): int {
                    $byBalance = $order->isDescending()
                        ? $balances[$b->id] <=> $balances[$a->id]
                        : $balances[$a->id] <=> $balances[$b->id];

                    return $byBalance !== 0 ? $byBalance : $a->name <=> $b->name;
                })
                : $inClass->sortBy(fn (Account $account): string => mb_strtolower($account->name), SORT_REGULAR, $order->isDescending());

            $groups[] = new BalanceSheetGroup(
                $class,
                $sorted->values()->all(),
                array_sum(array_map(fn (Account $account): int => $balances[$account->id], $inClass->all())),
                array_intersect_key($balances, $inClass->keyBy('id')->all()),
            );
        }

        return $groups;
    }

    /**
     * End-of-day balances per account and day. A day without a row keeps the balance of the
     * last earlier row, including one before $from. A day before an account's first row keeps
     * that row's start balance: the history only starts from 0 when the account has an opening
     * balance, and otherwise begins from the balance the account already carried.
     *
     * @param  list<int>  $accountIds
     * @return array<int, array<string, int>>
     */
    private function dailyBalances(array $accountIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = $this->days($from, $to);
        $result = [];

        if ($accountIds === []) {
            return $result;
        }

        $latestBefore = AccountDailyBalance::query()
            ->toBase()
            ->whereIn('account_id', $accountIds)
            ->whereDate('balance_date', '<', $from->toDateString())
            ->selectRaw('account_id, max(date(balance_date)) as latest_day')
            ->groupBy('account_id');

        $carried = AccountDailyBalance::query()
            ->toBase()
            ->joinSub($latestBefore, 'latest', function ($join): void {
                $join->on('account_daily_balances.account_id', '=', 'latest.account_id')
                    ->whereRaw('date(account_daily_balances.balance_date) = latest.latest_day');
            })
            ->pluck('account_daily_balances.end_balance', 'account_daily_balances.account_id');

        $rows = AccountDailyBalance::query()
            ->toBase()
            ->whereIn('account_id', $accountIds)
            ->whereDate('balance_date', '>=', $from->toDateString())
            ->whereDate('balance_date', '<=', $to->toDateString())
            ->selectRaw('account_id, date(balance_date) as day, start_balance, end_balance')
            ->orderBy('balance_date')
            ->get()
            ->groupBy('account_id');

        foreach ($accountIds as $accountId) {
            $accountRows = $rows->get($accountId, collect());
            $byDay = $accountRows->pluck('end_balance', 'day');
            $balance = (int) ($carried[$accountId] ?? $accountRows->first()->start_balance ?? 0);

            foreach ($days as $day) {
                $balance = (int) ($byDay[$day] ?? $balance);
                $result[$accountId][$day] = $balance;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function days(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];

        for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }
}
