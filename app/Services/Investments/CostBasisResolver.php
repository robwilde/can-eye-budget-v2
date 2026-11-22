<?php

declare(strict_types=1);

namespace App\Services\Investments;

use App\Enums\CostBasisSource;
use App\Enums\TradeActivity;
use App\Exceptions\HoldingRefusedException;
use App\Models\Holding;
use App\Models\Trade;
use App\Models\User;
use App\Support\Investments\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Cost per share of a holding, in cents of its currency. A manual value wins, then the average cost
 * worked out from the account's trades in that currency, then a provider's value. The average-cost
 * walk is shared with GainsCalculator through position(). Every write takes the user, locks the holding
 * row and decides on the locked row, so a stale copy cannot overwrite a value locked in the meantime.
 */
final class CostBasisResolver
{
    /**
     * The position in a security on an account, in one currency, just before $before, or after every
     * trade when it is null, by average cost in trade order (date, then id). A buy or reinvestment adds
     * its amount (fees included) to the cost; a sell or sweep-out removes shares at the running average,
     * so the average does not move; a position never goes below zero shares. Shares that arrived by a
     * sweep-in come from an internal movement and have no known cost, so the position is unknown for as
     * long as it holds any: once it is sold down to zero the next purchases start a known position.
     *
     * @return array{quantity: string, cost: string, unknown: bool, oversold: bool}
     */
    public function position(int $accountId, int $securityId, string $currency, ?Trade $before = null): array
    {
        $trades = Trade::query()
            ->where('account_id', $accountId)
            ->where('security_id', $securityId)
            ->where('currency', $currency)
            ->whereIn('activity', array_map(fn (TradeActivity $activity): string => $activity->value, array_filter(TradeActivity::cases(), fn (TradeActivity $activity): bool => $activity->movesShares())))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $quantity = '0.00000000';
        $cost = '0.0000000000000000';
        $unknown = false;
        $oversold = false;

        foreach ($trades as $trade) {
            if ($before !== null && ($trade->date->toDateString() > $before->date->toDateString() || ($trade->date->toDateString() === $before->date->toDateString() && $trade->id >= $before->id))) {
                break;
            }

            if ($trade->activity === TradeActivity::SweepIn) {
                $unknown = true;
            }

            if (! $trade->activity->reducesPosition()) {
                $quantity = Decimal::add($quantity, $trade->quantity);
                $cost = bcadd($cost, (string) $trade->amount, 16);

                continue;
            }

            $sold = Decimal::abs($trade->quantity);

            if (Decimal::compare($sold, $quantity) > 0) {
                $oversold = true;
                $sold = $quantity;
            }

            if (! Decimal::isZero($quantity)) {
                $cost = bcsub($cost, bcmul(bcdiv($cost, $quantity, 16), $sold, 16), 16);
            }

            $quantity = Decimal::sub($quantity, $sold);

            if (Decimal::isZero($quantity)) {
                $cost = '0.0000000000000000';
                $unknown = false;
            }
        }

        return ['quantity' => $quantity, 'cost' => $cost, 'unknown' => $unknown, 'oversold' => $oversold];
    }

    /**
     * The average cost per share worked out from the account's trades in a currency, or null when
     * there are no acquisitions or any shares held arrived through an internal movement.
     */
    public function calculated(int $accountId, int $securityId, string $currency): ?int
    {
        $position = $this->position($accountId, $securityId, $currency);

        if ($position['unknown'] || Decimal::isZero($position['quantity'])) {
            return null;
        }

        return Decimal::toCents(bcdiv($position['cost'], $position['quantity'], 16));
    }

    /**
     * @return array{perShare: int, source: CostBasisSource}|null
     */
    public function resolve(Holding $holding): ?array
    {
        if ($holding->cost_basis_source === CostBasisSource::Manual && $holding->cost_basis_per_share !== null) {
            return ['perShare' => $holding->cost_basis_per_share, 'source' => CostBasisSource::Manual];
        }

        $calculated = $this->calculated($holding->account_id, $holding->security_id, $holding->currency);

        if ($calculated !== null) {
            return ['perShare' => $calculated, 'source' => CostBasisSource::Calculated];
        }

        if ($holding->cost_basis_source === CostBasisSource::Provider && $holding->cost_basis_per_share !== null) {
            return ['perShare' => $holding->cost_basis_per_share, 'source' => CostBasisSource::Provider];
        }

        return null;
    }

    /**
     * Sets the total cost the user paid for the whole holding. It is stored per share and locked, so a
     * provider or a recalculation does not overwrite it.
     *
     * @throws HoldingRefusedException when the holding is not the user's or has no shares to spread the cost over
     */
    public function setManual(User $user, Holding $holding, int $totalCents): Holding
    {
        return DB::transaction(function () use ($user, $holding, $totalCents): Holding {
            $locked = $this->lock($user, $holding);

            if (Decimal::isZero($locked->quantity)) {
                throw new HoldingRefusedException('A cost basis needs a holding with shares.');
            }

            $locked->forceFill([
                'cost_basis_per_share' => Decimal::perShare($totalCents, $locked->quantity),
                'cost_basis_source' => CostBasisSource::Manual,
                'cost_basis_locked' => true,
            ])->save();

            return $locked;
        });
    }

    /**
     * Unlocking keeps the manual value until a calculated one replaces it; a provider value never does.
     *
     * @throws HoldingRefusedException when the holding is not the user's
     */
    public function unlock(User $user, Holding $holding): Holding
    {
        return DB::transaction(function () use ($user, $holding): Holding {
            $locked = $this->lock($user, $holding);
            $locked->forceFill(['cost_basis_locked' => false])->save();

            return $locked;
        });
    }

    /**
     * A provider's cost per share. It changes nothing while the cost is locked or manual.
     *
     * @throws HoldingRefusedException when the holding is not the user's
     */
    public function applyProviderValue(User $user, Holding $holding, int $perShareCents): Holding
    {
        return DB::transaction(function () use ($user, $holding, $perShareCents): Holding {
            $locked = $this->lock($user, $holding);

            if ($locked->cost_basis_locked || $locked->cost_basis_source === CostBasisSource::Manual) {
                return $locked;
            }

            $locked->forceFill(['cost_basis_per_share' => $perShareCents, 'cost_basis_source' => CostBasisSource::Provider])->save();

            return $locked;
        });
    }

    /**
     * Replaces an unlocked cost with the one calculated from trades, when there is one.
     *
     * @throws HoldingRefusedException when the holding is not the user's
     */
    public function applyCalculated(User $user, Holding $holding): Holding
    {
        return DB::transaction(function () use ($user, $holding): Holding {
            $locked = $this->lock($user, $holding);

            if ($locked->cost_basis_locked) {
                return $locked;
            }

            $calculated = $this->calculated($locked->account_id, $locked->security_id, $locked->currency);

            if ($calculated === null) {
                return $locked;
            }

            $locked->forceFill(['cost_basis_per_share' => $calculated, 'cost_basis_source' => CostBasisSource::Calculated])->save();

            return $locked;
        });
    }

    private function lock(User $user, Holding $holding): Holding
    {
        return Holding::query()->forUser($user)->whereKey($holding->id)->orderBy('id')->lockForUpdate()->first()
            ?? throw new HoldingRefusedException('That holding is not on one of your accounts.');
    }
}
