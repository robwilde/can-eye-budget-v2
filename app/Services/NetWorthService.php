<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\BalanceSheet;
use App\DTOs\BalanceSheetGroup;
use App\Enums\AccountClass;
use App\Enums\AccountOrder;
use App\Enums\ChartGranularity;
use App\Enums\TransactionPeriod;
use App\Enums\ValuationKind;
use App\Models\Account;
use App\Models\AccountDailyBalance;
use App\Models\AccountValuation;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
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
     * Net worth for each day from $from to $to inclusive, keyed by Y-m-d, or only for $onlyDays (days
     * inside the range) when given, which spares converting the others. An account that was
     * disabled counts through the day before it was disabled and not from that day on.
     *
     * @param  list<string>|null  $onlyDays
     * @return array<string, int>
     */
    public function series(User $user, CarbonImmutable $from, CarbonImmutable $to, bool $flatBeforeHistory = false, ?array $onlyDays = null): array
    {
        $accounts = $this->countedAccounts($user, includeDisabled: true)->get(['id', 'disabled_at', 'currency']);
        $balances = $this->dailyBalances($accounts->pluck('id')->all(), $from, $to, $flatBeforeHistory);
        $converter = CurrencyConverter::enabled() ? app(CurrencyConverter::class) : null;

        $series = [];

        foreach ($onlyDays ?? $this->days($from, $to) as $day) {
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
    public function accountSeries(User $user, array $accountIds, CarbonImmutable $from, CarbonImmutable $to, bool $flatBeforeHistory = false): array
    {
        $ownedIds = $user->accounts()->whereIn('id', $accountIds)->pluck('id')->all();

        return $this->dailyBalances($ownedIds, $from, $to, $flatBeforeHistory);
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
     * Net worth over the dashboard period at its chart granularity: every day for a daily range,
     * the last day of each week or month for coarser ones, always including the first and the last
     * day. The range ends no later than today. An account that starts from an opening balance inside
     * the window is held at that balance before it, never drawn as growth from zero. Only the days
     * that are plotted are converted into the base currency.
     *
     * @return array<string, int>
     */
    public function chartSeries(User $user, DashboardPeriod $period): array
    {
        [$from, $to] = $this->window($user, $period);

        if ($period->granularity === ChartGranularity::Daily) {
            return $this->series($user, $from, $to, flatBeforeHistory: true);
        }

        $plotted = $this->plottedDays($from, $to, $period->granularity);

        return $this->series($user, $from, $to, flatBeforeHistory: true, onlyDays: $plotted);
    }

    /**
     * The days a chart at $granularity plots between $from and $to: the last day of each week or
     * month, always with the first and the last day.
     *
     * @return list<string>
     */
    public function plottedDays(CarbonImmutable $from, CarbonImmutable $to, ChartGranularity $granularity): array
    {
        $days = $this->days($from, $to);

        if ($granularity === ChartGranularity::Daily) {
            return $days;
        }

        $first = $days[0];
        $last = $days[array_key_last($days)];

        return array_values(array_filter($days, function (string $day) use ($granularity, $first, $last): bool {
            $date = CarbonImmutable::parse($day);
            $bucketEnd = $granularity === ChartGranularity::Weekly ? $date->isSunday() : $date->isSameDay($date->endOfMonth());

            return $bucketEnd || $day === $first || $day === $last;
        }));
    }

    /**
     * How much net worth moved over the period, or null when the balance history does not reach
     * back to the start of the window (an account whose history begins later, or has none), since a
     * change measured from a partial history would read as growth from nothing. All Time is the whole
     * recorded history, so it starts at the earliest history day.
     */
    public function velocity(User $user, DashboardPeriod $period): ?int
    {
        [$from, $to] = $this->window($user, $period);

        return $this->changeBetween($user, $from, $to, $period->period === TransactionPeriod::All);
    }

    /**
     * How much net worth moved from $from to $to, or null when the balance history does not reach
     * back to $from. With $fromEarliestHistory the change starts at the earliest history day.
     */
    public function changeBetween(User $user, CarbonImmutable $from, CarbonImmutable $to, bool $fromEarliestHistory = false): ?int
    {
        if ($fromEarliestHistory) {
            $earliest = $this->earliestHistoryDay($user);

            if ($earliest === null) {
                return null;
            }

            $from = $from->max($earliest);
        } elseif (! $this->historyCovers($user, $from)) {
            return null;
        }

        $series = $this->series($user, $from, $to, onlyDays: [$from->toDateString(), $to->toDateString()]);

        return end($series) - reset($series);
    }

    /**
     * Whether every counted account has balance history reaching back to $from, so a change measured
     * from there is not growth from nothing. False when the user has no counted account.
     */
    public function historyCovers(User $user, CarbonImmutable $from): bool
    {
        $accountIds = $this->countedAccounts($user)->pluck('id')->all();

        if ($accountIds === []) {
            return false;
        }

        $firstDays = $this->firstHistoryDays($accountIds);

        foreach ($accountIds as $accountId) {
            if (! isset($firstDays[$accountId]) || $firstDays[$accountId] > $from->toDateString()) {
                return false;
            }
        }

        return true;
    }

    /**
     * The first day any counted account has balance history, or null when none has.
     */
    public function earliestHistoryDay(User $user): ?CarbonImmutable
    {
        $accountIds = $this->countedAccounts($user)->pluck('id')->all();

        if ($accountIds === []) {
            return null;
        }

        $first = $this->firstHistoryDays($accountIds)->min();

        return $first === null ? null : CarbonImmutable::parse((string) $first);
    }

    /**
     * Each balance-sheet group's signed balance over time at the days given, keyed by side
     * ('assets' or 'liabilities'), then account class value, then Y-m-d: the sum of its accounts' own
     * series (accountSeries, an opening-balance account held at its first balance before its history),
     * converted into the base currency at each day's rate. A class can sit on both sides (a credit
     * card typed as a transaction account), so the side is part of the key.
     *
     * @param  list<string>  $days  days inside $from to $to
     * @return array<string, array<string, array<string, int>>>
     */
    public function groupSeries(User $user, BalanceSheet $sheet, CarbonImmutable $from, CarbonImmutable $to, array $days): array
    {
        $converter = CurrencyConverter::enabled() ? app(CurrencyConverter::class) : null;
        $sides = ['assets' => $sheet->assets, 'liabilities' => $sheet->liabilities];
        $accountIds = [];

        foreach ($sides as $groups) {
            foreach ($groups as $group) {
                $accountIds = [...$accountIds, ...array_keys($group->balances)];
            }
        }

        $balances = $this->accountSeries($user, $accountIds, $from, $to, flatBeforeHistory: true);
        $series = [];

        foreach ($sides as $side => $groups) {
            foreach ($groups as $group) {
                foreach ($days as $day) {
                    $total = 0;

                    foreach ($group->accounts as $account) {
                        $balance = $balances[$account->id][$day];
                        $total += $converter === null
                            ? $balance
                            : $converter->convert($balance, $account->currency, $user->base_currency, CarbonImmutable::parse($day));
                    }

                    $series[$side][$group->class->value][$day] = $total;
                }
            }
        }

        return $series;
    }

    /**
     * The first and last day of the period as whole days, the last no later than today and the first
     * no later than the last, so a range that reaches into the future is not drawn as recorded history.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(User $user, DashboardPeriod $period): array
    {
        $to = $period->end->startOfDay()->min($user->today()->startOfDay());

        return [$period->start->startOfDay()->min($to), $to];
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
     * The first day each of the accounts has balance history, keyed by account id; an account with
     * no history is absent.
     *
     * @param  list<int>  $accountIds
     * @return Collection<int, string>
     */
    private function firstHistoryDays(array $accountIds): Collection
    {
        return AccountDailyBalance::query()
            ->toBase()
            ->whereIn('account_id', $accountIds)
            ->selectRaw('account_id, min(date(balance_date)) as first_day')
            ->groupBy('account_id')
            ->pluck('first_day', 'account_id');
    }

    /**
     * End-of-day balances per account and day: an account's total, which is its cash plus the value of
     * its securities when the history splits them (the non-cash columns are null otherwise). A day without a row keeps the balance of the
     * last earlier row, including one before $from. A day before an account's first row keeps
     * that row's start balance: the history only starts from 0 when the account has an opening
     * balance, and otherwise begins from the balance the account already carried. With
     * $flatBeforeHistory an opening-balance account is instead held at its first balance before
     * its history starts, so the first balance never shows as growth from zero.
     *
     * @param  list<int>  $accountIds
     * @return array<int, array<string, int>>
     */
    private function dailyBalances(array $accountIds, CarbonImmutable $from, CarbonImmutable $to, bool $flatBeforeHistory = false): array
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
            ->selectRaw('account_daily_balances.account_id, account_daily_balances.end_balance + coalesce(account_daily_balances.end_non_cash_balance, 0) as end_balance')
            ->pluck('end_balance', 'account_id');

        $rows = AccountDailyBalance::query()
            ->toBase()
            ->whereIn('account_id', $accountIds)
            ->whereDate('balance_date', '>=', $from->toDateString())
            ->whereDate('balance_date', '<=', $to->toDateString())
            ->selectRaw('account_id, date(balance_date) as day, start_balance + coalesce(start_non_cash_balance, 0) as start_balance, end_balance + coalesce(end_non_cash_balance, 0) as end_balance')
            ->orderBy('balance_date')
            ->get()
            ->groupBy('account_id');

        $opening = $flatBeforeHistory
            ? AccountValuation::query()->toBase()->whereIn('account_id', $accountIds)->where('kind', ValuationKind::OpeningBalance->value)->pluck('account_id')->flip()
            : collect();

        foreach ($accountIds as $accountId) {
            $accountRows = $rows->get($accountId, collect());
            $byDay = $accountRows->pluck('end_balance', 'day');
            $first = $accountRows->first();
            $balance = (int) ($carried[$accountId] ?? $first->start_balance ?? 0);

            if ($first !== null && $balance === 0 && ! isset($carried[$accountId]) && $opening->has($accountId)) {
                $balance = (int) $first->end_balance;
            }

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
