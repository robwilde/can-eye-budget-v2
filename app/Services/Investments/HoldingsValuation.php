<?php

declare(strict_types=1);

namespace App\Services\Investments;

use App\Enums\TradeActivity;
use App\Models\Account;
use App\Models\SecurityPrice;
use App\Models\Trade;
use App\Services\CurrencyConverter;
use App\Services\ExchangeRateLookup;
use App\Support\Investments\Decimal;
use Carbon\CarbonImmutable;

/**
 * What an account's securities were worth at the end of each day, in the account's currency, worked
 * out from its trades and the stored security prices. The position of each security in each currency
 * is the running sum of its trades. A day's price is the latest stored price on or before it; before
 * any price is stored the position is valued at its last trade price, never at zero. A position in
 * another currency is converted at the day's rate when budget.multi_currency is on. A day also
 * carries what the day's trades moved into (positive) or out of (negative) the securities, so the
 * value's change that is not a trade is the market movement. Only a Buy, Sell, Reinvestment or sweep
 * moves shares. Quantity times price is done in Decimal and rounded to whole cents. Rates come from the
 * stored rates only (never the provider), because this runs inside a rebuild that holds the account's
 * lock; a day with no stored rate counts at 1, as in CurrencyConverter.
 */
final class HoldingsValuation
{
    /**
     * @return array<string, array{value: int, invested: int}> end-of-day figures keyed by Y-m-d, from
     *                                                         the day of the first trade to $until; empty when the account has no share trades
     */
    public function series(Account $account, CarbonImmutable $until): array
    {
        $trades = Trade::query()
            ->where('account_id', $account->id)
            ->whereNotNull('security_id')
            ->whereIn('activity', array_map(fn (TradeActivity $activity): string => $activity->value, array_filter(TradeActivity::cases(), fn (TradeActivity $activity): bool => $activity->movesShares())))
            ->whereDate('date', '<=', $until->toDateString())
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        if ($trades->isEmpty()) {
            return [];
        }

        $converter = CurrencyConverter::enabled() ? new CurrencyConverter(new ExchangeRateLookup(new StoredRatesOnly)) : null;
        $prices = $this->prices($trades->pluck('security_id')->unique()->all(), $until);
        $byDay = $trades->groupBy(fn (Trade $trade): string => $trade->date->toDateString());

        $positions = [];
        $cursors = [];
        $lastTradePrice = [];
        $series = [];

        for ($day = CarbonImmutable::parse($trades->first()->date->toDateString()); $day->lessThanOrEqualTo($until); $day = $day->addDay()) {
            $key = $day->toDateString();
            $invested = 0;

            foreach ($byDay->get($key, []) as $trade) {
                $position = $trade->security_id.'|'.$trade->currency;
                $positions[$position] = Decimal::add($positions[$position] ?? '0', $trade->quantity);

                $lastTradePrice[$position] = $trade->price;

                $invested += $this->convert($converter, $trade->amount, $trade->currency, $account->currency, $day);
            }

            $value = 0;

            foreach ($positions as $position => $quantity) {
                if (Decimal::isZero($quantity)) {
                    continue;
                }

                $cursors[$position] ??= -1;
                $price = $this->priceOn($prices[$position] ?? [], $key, $cursors[$position]) ?? $lastTradePrice[$position] ?? 0;
                $currency = mb_substr($position, mb_strpos($position, '|') + 1);
                $value += $this->convert($converter, Decimal::mulCents($quantity, $price), $currency, $account->currency, $day);
            }

            $series[$key] = ['value' => $value, 'invested' => $invested];
        }

        return $series;
    }

    /**
     * @param  list<int>  $securityIds
     * @return array<string, list<array{string, int}>> [date, price] pairs by "security|currency", oldest first
     */
    private function prices(array $securityIds, CarbonImmutable $until): array
    {
        $prices = [];

        $rows = SecurityPrice::query()
            ->whereIn('security_id', $securityIds)
            ->whereDate('date', '<=', $until->toDateString())
            ->orderBy('date')
            ->get(['security_id', 'currency', 'date', 'price']);

        foreach ($rows as $row) {
            $prices[$row->security_id.'|'.$row->currency][] = [$row->date->toDateString(), (int) $row->price];
        }

        return $prices;
    }

    /**
     * The latest price on or before $day. Days are visited in order, so the cursor only moves forward.
     *
     * @param  list<array{string, int}>  $prices
     */
    private function priceOn(array $prices, string $day, int &$cursor): ?int
    {
        while (isset($prices[$cursor + 1]) && $prices[$cursor + 1][0] <= $day) {
            $cursor++;
        }

        return $cursor < 0 ? null : $prices[$cursor][1];
    }

    private function convert(?CurrencyConverter $converter, int $cents, string $from, string $to, CarbonImmutable $day): int
    {
        return $converter === null ? $cents : $converter->convert($cents, $from, $to, $day);
    }
}
