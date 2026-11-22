<?php

declare(strict_types=1);

namespace App\Services\Investments;

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
     * The statement panel of one investment account. The account's balance is its total, as it is reconciled;
     * cash is that total less the value of the holdings.
     *
     * @return array{rows: list<Row>, cash: int, holdingsValue: int, portfolioValue: int, top: list<Row>, dayChange: int|null, unrealised: int|null, periodReturnBp: int|null, contributions: int, dividends: int, interest: int}
     */
    public function statement(User $user, Account $account): array
    {
        $converter = CurrencyConverter::enabled() ? app(CurrencyConverter::class) : null;
        $today = $user->today();
        $rows = $this->rows($user, [$account], $converter);
        $holdingsValue = array_sum(array_column($rows, 'valueBase'));
        $portfolioValue = $this->base($user, $converter, $account->balance, $account->currency, $today);

        $known = array_filter($rows, static fn (array $row): bool => $row['unrealised'] !== null);
        $changes = array_filter($rows, static fn (array $row): bool => $row['dayChange'] !== null);

        return [
            'rows' => $rows,
            'cash' => $portfolioValue - $holdingsValue,
            'holdingsValue' => $holdingsValue,
            'portfolioValue' => $portfolioValue,
            'top' => array_slice($rows, 0, self::TOP),
            'dayChange' => $changes === [] ? null : array_sum(array_map(fn (array $row): int => $this->base($user, $converter, $row['dayChange'], $row['holding']->currency, $today), $changes)),
            'unrealised' => $known === [] ? null : array_sum(array_map(fn (array $row): int => $this->base($user, $converter, $row['unrealised'], $row['holding']->currency, $today), $known)),
            'periodReturnBp' => $this->periodReturnBp($user, $rows, $today->subDays(self::PERIOD_DAYS), $converter),
            'contributions' => $this->contributions($user, $account, $converter),
            'dividends' => $this->income($user, $account, TradeActivity::Dividend, $converter),
            'interest' => $this->income($user, $account, TradeActivity::Interest, $converter),
        ];
    }

    /**
     * How much the current holdings gained in price between $from and now, as basis points of what they
     * were worth at $from. Only holdings with a stored price on or before $from and a latest price count;
     * null when none does. Flows are ignored: it is the return of what is held now.
     *
     * @param  list<Row>  $rows
     */
    public function periodReturnBp(User $user, array $rows, CarbonImmutable $from, ?CurrencyConverter $converter = null): ?int
    {
        $today = $user->today();
        $gain = 0;
        $start = 0;

        foreach ($rows as $row) {
            $then = $this->gains->priceOn($row['holding'], $from);

            if ($then === null || $row['price'] === null) {
                continue;
            }

            $before = $this->base($user, $converter, Decimal::mulCents($row['quantity'], $then), $row['holding']->currency, $today);
            $start += $before;
            $gain += $row['valueBase'] - $before;
        }

        return $start > 0 ? $this->basisPoints($gain, $start) : null;
    }

    /**
     * Everything paid into the account less everything paid out, all time: its posted credits less its
     * posted debits, each by absolute amount (a feed may store debits as negatives), split children
     * left out so a split is not counted twice, converted to the base currency like the other figures.
     * Dividends, interest, fees and trades are trade rows, not transactions, so they do not count here.
     */
    public function contributions(User $user, Account $account, ?CurrencyConverter $converter = null): int
    {
        $row = Transaction::query()
            ->where('account_id', $account->id)
            ->whereNull('split_parent_id')
            ->current()
            ->where('status', TransactionStatus::Posted)
            ->selectRaw("coalesce(sum(case when direction = 'credit' then abs(amount) else 0 end), 0) as inflows")
            ->selectRaw("coalesce(sum(case when direction = 'debit' then abs(amount) else 0 end), 0) as outflows")
            ->toBase()
            ->first();

        return $this->base($user, $converter, (int) $row->inflows - (int) $row->outflows, $account->currency, $user->today());
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
