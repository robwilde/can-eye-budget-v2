<?php

declare(strict_types=1);

namespace App\Services\Investments;

use App\Enums\TradeActivity;
use App\Exceptions\HoldingRefusedException;
use App\Models\Holding;
use App\Models\SecurityPrice;
use App\Models\Trade;
use App\Models\User;
use App\Services\ExchangeRateLookup;
use App\Support\Investments\Decimal;

/**
 * Realised and unrealised gains in cents. A gain is null, never a guess, when the cost is unknown.
 */
final readonly class GainsCalculator
{
    public function __construct(
        private CostBasisResolver $costBasis,
        private ExchangeRateLookup $rates,
    ) {}

    /**
     * The gain on a sell at average cost: the proceeds net of the fee less the cost of the shares sold.
     * Only a sell has one: transfer and sweep activity never realise a gain. It is null when the shares
     * sold exceed what the trades say was held, when any shares arrived through an internal movement,
     * and when the trade is in another currency than the user's and no rate is known for its day.
     * A gain in another currency is converted at the rate of the trade's date.
     *
     * @throws HoldingRefusedException when the trade is not on one of the user's accounts
     */
    public function realised(User $user, Trade $trade): ?int
    {
        if (! Trade::query()->forUser($user)->whereKey($trade->id)->exists()) {
            throw new HoldingRefusedException('That trade is not on one of your accounts.');
        }

        if ($trade->activity !== TradeActivity::Sell || $trade->security_id === null) {
            return null;
        }

        $position = $this->costBasis->position($trade->account_id, $trade->security_id, $trade->currency, $trade);
        $sold = Decimal::abs($trade->quantity);

        if ($position['unknown'] || Decimal::isZero($position['quantity']) || Decimal::compare($sold, $position['quantity']) > 0) {
            return null;
        }

        $cost = bcmul(bcdiv($position['cost'], $position['quantity'], 16), $sold, 16);
        $gain = Decimal::toCents(bcsub((string) -$trade->amount, $cost, 16));

        if ($trade->currency === $user->base_currency) {
            return $gain;
        }

        $rate = $this->rates->rateFor($trade->currency, $user->base_currency, $trade->date);

        return $rate === null ? null : (int) round($gain * $rate);
    }

    /**
     * Quantity times the price less the cost per share, in the holding's currency. The price is the
     * latest one stored for the security in that currency unless given. Null when the cost or the price
     * is unknown.
     *
     * @throws HoldingRefusedException when the holding is not on one of the user's accounts
     */
    public function unrealised(User $user, Holding $holding, ?int $price = null): ?int
    {
        if (! Holding::query()->forUser($user)->whereKey($holding->id)->exists()) {
            throw new HoldingRefusedException('That holding is not on one of your accounts.');
        }

        $cost = $this->costBasis->resolve($holding);
        $price ??= SecurityPrice::query()
            ->where('security_id', $holding->security_id)
            ->where('currency', $holding->currency)
            ->orderByDesc('date')
            ->value('price');

        if ($cost === null || $price === null) {
            return null;
        }

        return Decimal::mulCents($holding->quantity, (int) $price - $cost['perShare']);
    }
}
