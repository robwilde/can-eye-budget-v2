<?php

declare(strict_types=1);

namespace App\Services\Investments;

use App\Enums\ImportSource;
use App\Models\Account;
use App\Models\Holding;
use App\Models\SecurityPrice;
use App\Models\Trade;
use App\Models\User;
use App\Support\Investments\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Keeps one holding row for an account's position in a security, in one currency, in step with its
 * trades: the quantity is the position, the amount is that quantity at the latest stored price (or at
 * cost when no price is known), the row is dated today, and an unlocked cost basis follows the average
 * cost from the trades. Earlier rows of the same position are folded into it, keeping the manual cost
 * basis and the remap origin they carried, so the newest row is always the whole position. A position
 * sold down to zero has no holding. A position with no trades, or on a provider-linked account whose holdings the provider owns, is left alone.
 */
final readonly class HoldingSnapshotter
{
    public function __construct(private CostBasisResolver $costBasis) {}

    public function refresh(User $user, int $accountId, int $securityId, string $currency): ?Holding
    {
        return DB::transaction(function () use ($user, $accountId, $securityId, $currency): ?Holding {
            $rows = Holding::query()
                ->forUser($user)
                ->where('account_id', $accountId)
                ->where('security_id', $securityId)
                ->where('currency', $currency)
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();

            $provided = Account::query()->whereKey($accountId)->where('import_source', ImportSource::Redbark)->exists();
            $traded = ! $provided && Trade::query()->where('account_id', $accountId)->where('security_id', $securityId)->where('currency', $currency)->exists();

            if (! $traded) {
                return $rows->first();
            }

            $position = $this->costBasis->position($accountId, $securityId, $currency);

            if (Decimal::isZero($position['quantity'])) {
                $rows->each->delete();

                return null;
            }

            $price = SecurityPrice::query()
                ->where('security_id', $securityId)
                ->where('currency', $currency)
                ->orderByDesc('date')
                ->value('price');

            $amount = $price !== null
                ? Decimal::mulCents($position['quantity'], (int) $price)
                : Decimal::toCents($position['cost']);

            $holding = $rows->shift() ?? new Holding(['account_id' => $accountId, 'security_id' => $securityId, 'currency' => $currency]);

            foreach ($rows as $older) {
                $holding->original_security_id ??= $older->original_security_id;
                $holding->provider_locked = $holding->provider_locked || $older->provider_locked;

                if (! $holding->cost_basis_locked && $older->cost_basis_locked) {
                    $holding->cost_basis_per_share = $older->cost_basis_per_share;
                    $holding->cost_basis_source = $older->cost_basis_source;
                    $holding->cost_basis_locked = true;
                }

                $older->delete();
            }

            $holding->forceFill(['date' => $user->today()->toDateString(), 'quantity' => $position['quantity'], 'amount' => $amount])->save();

            return $this->costBasis->applyCalculated($user, $holding);
        });
    }
}
