<?php

declare(strict_types=1);

namespace App\Services\Investments;

use App\Enums\AccountClass;
use App\Enums\CostBasisSource;
use App\Enums\TradeActivity;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Holding;
use App\Models\Security;
use App\Models\Trade;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CurrencyConverter;
use App\Support\Investments\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What an investment account (or several) holds and how it is doing, from the holdings, the stored
 * prices and the trades, through CostBasisResolver and GainsCalculator. A figure that cannot be known
 * (a cost basis of unknown origin, a price that was never fetched) is null, never a guess. Totals are in
 * the user's base currency when budget.multi_currency is on and in the stored cents otherwise.
 *
 * @phpstan-type Row array{holding: Holding, security: Security, quantity: string, price: int|null, value: int, valueBase: int, costPerShare: int|null, costSource: CostBasisSource|null, unrealised: int|null, returnBp: int|null, dayChange: int|null, weightBp: int}
 */
final readonly class InvestmentPortfolio
{
    public const int TOP = 5;

    public const int PERIOD_DAYS = 30;

    public function __construct(
        private CostBasisResolver $costBasis,
        private GainsCalculator $gains,
    ) {}

    /**
     * The current holding of each security in each currency on the accounts, largest first.
     *
     * @param  list<Account>  $accounts  accounts of the user
     * @return list<Row>
     */
    public function rows(User $user, array $accounts, ?CurrencyConverter $converter = null): array
    {
        $converter ??= CurrencyConverter::enabled() ? app(CurrencyConverter::class) : null;
        $today = $user->today();

        $latest = [];

        foreach (Holding::query()->forUser($user)->whereIn('account_id', array_map(fn (Account $account): int => $account->id, $accounts))->with('security')->orderBy('date')->orderBy('id')->get() as $holding) {
            $latest[$holding->account_id.'|'.$holding->security_id.'|'.$holding->currency] = $holding;
        }

        $rows = [];

        foreach ($latest as $holding) {
            if (Decimal::isZero($holding->quantity)) {
                continue;
            }

            $price = $this->gains->latestPrice($holding);
            $value = $price !== null ? Decimal::mulCents($holding->quantity, $price) : $holding->amount;
            $cost = $this->costBasis->resolve($holding);
            $unrealised = $this->gains->unrealised($user, $holding, $price);
            $basis = $cost === null ? null : Decimal::mulCents($holding->quantity, $cost['perShare']);

            $rows[] = [
                'holding' => $holding,
                'security' => $holding->security,
                'quantity' => $holding->quantity,
                'price' => $price,
                'value' => $value,
                'valueBase' => $this->base($user, $converter, $value, $holding->currency, $today),
                'costPerShare' => $cost['perShare'] ?? null,
                'costSource' => $cost['source'] ?? null,
                'unrealised' => $unrealised,
                'returnBp' => $unrealised === null || $basis === null || $basis === 0 ? null : $this->basisPoints($unrealised, $basis),
                'dayChange' => $this->gains->dayChange($holding),
                'weightBp' => 0,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$b['valueBase'], $a['security']->ticker] <=> [$a['valueBase'], $b['security']->ticker]);

        $total = array_sum(array_column($rows, 'valueBase'));

        foreach ($rows as $index => $row) {
            $rows[$index]['weightBp'] = $total > 0 ? $this->basisPoints($row['valueBase'], $total) : 0;
        }

        return $rows;
    }

    /**
     * The investment accounts the dashboard and the reports cover: the user's active, tracked ones that are not left out of reports.
     *
     * @return list<Account>
     */
    public function accounts(User $user): array
    {
        return Account::query()->where('user_id', $user->id)->where('type', AccountClass::Investment)->active()->tracked()->inReports()->orderBy('id')->get()->all();
    }

    /**
     * Whether any of the accounts holds a position. The balance of an account is its total, as it is
     * reconciled, so an account without holdings is all cash.
     *
     * @param  list<Account>  $accounts
     */
    public function hasHoldings(User $user, array $accounts): bool
    {
        return $accounts !== [] && Holding::query()->forUser($user)->whereIn('account_id', array_map(static fn (Account $account): int => $account->id, $accounts))->where('quantity', '>', 0)->exists();
    }

    /**
     * The figures of one or more investment accounts together. The balance of an account is its total,
     * as it is reconciled; cash is the total less the value of the holdings. The return of the period is
     * the price return of what is held now between $from and $to (today when null).
     *
     * @param  list<Account>  $accounts  accounts of the user
     * @return array{rows: list<Row>, cash: int, holdingsValue: int, portfolioValue: int, top: list<Row>, dayChange: int|null, unrealised: int|null, totalReturnBp: int|null, periodReturnBp: int|null}
     */
    public function overview(User $user, array $accounts, CarbonImmutable $from, ?CarbonImmutable $to = null): array
    {
        $converter = CurrencyConverter::enabled() ? app(CurrencyConverter::class) : null;
        $today = $user->today();
        $rows = $this->rows($user, $accounts, $converter);
        $holdingsValue = array_sum(array_column($rows, 'valueBase'));
        $portfolioValue = array_sum(array_map(fn (Account $account): int => $this->base($user, $converter, $account->balance, $account->currency, $today), $accounts));

        $known = array_filter($rows, static fn (array $row): bool => $row['unrealised'] !== null);
        $changes = array_filter($rows, static fn (array $row): bool => $row['dayChange'] !== null);
        $unrealised = $known === [] ? null : array_sum(array_map(fn (array $row): int => $this->base($user, $converter, $row['unrealised'], $row['holding']->currency, $today), $known));
        $basis = array_sum(array_map(fn (array $row): int => $this->base($user, $converter, Decimal::mulCents($row['quantity'], $row['costPerShare']), $row['holding']->currency, $today), $known));

        return [
            'rows' => $rows,
            'cash' => $portfolioValue - $holdingsValue,
            'holdingsValue' => $holdingsValue,
            'portfolioValue' => $portfolioValue,
            'top' => array_slice($rows, 0, self::TOP),
            'dayChange' => $changes === [] ? null : array_sum(array_map(fn (array $row): int => $this->base($user, $converter, $row['dayChange'], $row['holding']->currency, $today), $changes)),
            'unrealised' => $unrealised,
            'totalReturnBp' => $unrealised === null || $basis <= 0 ? null : $this->basisPoints($unrealised, $basis),
            'periodReturnBp' => $this->periodReturnBp($user, $rows, $from, $to, $converter),
        ];
    }

    /**
     * The statement panel of one investment account: its overview over the last 30 days plus its
     * contributions, dividends and interest.
     *
     * @return array{rows: list<Row>, cash: int, holdingsValue: int, portfolioValue: int, top: list<Row>, dayChange: int|null, unrealised: int|null, totalReturnBp: int|null, periodReturnBp: int|null, contributions: int, dividends: int, interest: int}
     */
    public function statement(User $user, Account $account): array
    {
        $converter = CurrencyConverter::enabled() ? app(CurrencyConverter::class) : null;
        $flows = $this->flows($user, $account, null, null, $converter);

        return [
            ...$this->overview($user, [$account], $user->today()->subDays(self::PERIOD_DAYS)),
            'contributions' => $flows['inflows'] - $flows['outflows'],
            'dividends' => $this->income($user, $account, TradeActivity::Dividend, $converter),
            'interest' => $this->income($user, $account, TradeActivity::Interest, $converter),
        ];
    }

    /**
     * How much the current holdings gained in price between $from and $to (today when null or later),
     * as basis points of what they were worth at $from. Only holdings with a stored price on or before
     * both days count; null when none does. Flows are ignored: it is the return of what is held now.
     *
     * @param  list<Row>  $rows
     */
    public function periodReturnBp(User $user, array $rows, CarbonImmutable $from, ?CarbonImmutable $to = null, ?CurrencyConverter $converter = null): ?int
    {
        $today = $user->today();
        $past = $to !== null && $to->lt($today);
        $gain = 0;
        $start = 0;

        foreach ($rows as $row) {
            $then = $this->gains->priceOn($row['holding'], $from);
            $end = $past ? $this->gains->priceOn($row['holding'], $to) : $row['price'];

            if ($then === null || $end === null) {
                continue;
            }

            $before = $this->base($user, $converter, Decimal::mulCents($row['quantity'], $then), $row['holding']->currency, $today);
            $after = $past ? $this->base($user, $converter, Decimal::mulCents($row['quantity'], $end), $row['holding']->currency, $today) : $row['valueBase'];
            $start += $before;
            $gain += $after - $before;
        }

        return $start > 0 ? $this->basisPoints($gain, $start) : null;
    }

    /**
     * What was paid into the account and what was paid out of it, all time or between two days: its
     * posted credits and its posted debits, each by absolute amount (a feed may store debits as
     * negatives), split children and rows excluded from totals left out, each converted to the base
     * currency at its own day like the other figures. A transfer whose other leg is on one of
     * $internalAccountIds moves money between accounts the figure already covers, so it is left out.
     * Dividends, interest, fees and trades are trade rows, not transactions, so they do not count here.
     *
     * @param  list<int>  $internalAccountIds
     * @return array{inflows: int, outflows: int}
     */
    public function flows(User $user, Account $account, ?CarbonInterface $from, ?CarbonInterface $to, ?CurrencyConverter $converter = null, array $internalAccountIds = []): array
    {
        $rows = Transaction::query()
            ->where('account_id', $account->id)
            ->whereNull('split_parent_id')
            ->where('excluded', false)
            ->current()
            ->where('status', TransactionStatus::Posted)
            ->when($internalAccountIds !== [], fn ($query) => $query->whereDoesntHave('transferPair', fn ($pair) => $pair->whereIn('account_id', $internalAccountIds)))
            ->when($from !== null, fn ($query) => $query->whereDate('post_date', '>=', $from->toDateString()))
            ->when($to !== null, fn ($query) => $query->whereDate('post_date', '<=', $to->toDateString()))
            ->when($converter !== null, fn ($query) => $query->selectRaw('date(post_date) as day')->groupBy('day'))
            ->selectRaw("coalesce(sum(case when direction = 'credit' then abs(amount) else 0 end), 0) as inflows")
            ->selectRaw("coalesce(sum(case when direction = 'debit' then abs(amount) else 0 end), 0) as outflows")
            ->toBase()
            ->get();

        $inflows = 0;
        $outflows = 0;

        foreach ($rows as $row) {
            $date = $converter === null ? $user->today() : CarbonImmutable::parse($row->day);
            $inflows += $this->base($user, $converter, (int) $row->inflows, $account->currency, $date);
            $outflows += $this->base($user, $converter, (int) $row->outflows, $account->currency, $date);
        }

        return ['inflows' => $inflows, 'outflows' => $outflows];
    }

    private function income(User $user, Account $account, TradeActivity $activity, ?CurrencyConverter $converter): int
    {
        $total = 0;

        foreach (Trade::query()->forUser($user)->where('account_id', $account->id)->where('activity', $activity)->get() as $trade) {
            $total += $this->base($user, $converter, -$trade->amount, $trade->currency, $trade->date);
        }

        return $total;
    }

    private function base(User $user, ?CurrencyConverter $converter, int $cents, string $currency, CarbonInterface $date): int
    {
        return $converter === null ? $cents : $converter->convert($cents, $currency, $user->base_currency, $date);
    }

    private function basisPoints(int $part, int $whole): int
    {
        return Decimal::toCents(bcdiv(bcmul((string) $part, '10000', 16), (string) $whole, 16));
    }
}
