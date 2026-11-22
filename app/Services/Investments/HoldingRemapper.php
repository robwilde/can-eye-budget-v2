<?php

declare(strict_types=1);

namespace App\Services\Investments;

use App\Enums\CostBasisSource;
use App\Exceptions\HoldingRefusedException;
use App\Models\Account;
use App\Models\Holding;
use App\Models\Security;
use App\Models\Trade;
use App\Models\User;
use App\Support\Investments\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moves an account's position in one security onto another, for a provider that gets the security
 * wrong. Every holding of the old security on that account, and its trades, move to the new one, each
 * remembering the security it came from. A holding that lands on a day and currency where the new
 * security already has one is merged into it: quantities and amounts are summed and the cost per share
 * is the average weighted by quantity, or unknown when either side's cost is. A merge cannot be
 * undone, so a merged holding keeps the survivor's own origin. reset() puts back only the rows that
 * were remapped from the original security; remapping back to the original security is a reset.
 * Everything runs in one transaction with the account row locked first and the holdings in id order.
 */
final readonly class HoldingRemapper
{
    public function __construct(private TradeRecorder $recorder, private HoldingSnapshotter $snapshotter) {}

    /**
     * @return Collection<int, Holding> the account's holdings of the new security after the remap
     *
     * @throws HoldingRefusedException when the holding is not on one of the user's accounts
     */
    public function remap(User $user, Holding $holding, Security $target): Collection
    {
        $own = $this->owned($user, $holding);

        if ($own->security_id === $target->id) {
            return $this->holdingsOf($own->account_id, $target->id);
        }

        return DB::transaction(fn (): Collection => $this->move($user, $own->account_id, $own->security_id, $target->id, onlyOriginal: false));
    }

    /**
     * Puts the holdings that were remapped from the holding's original security back on it, with
     * their trades.
     *
     * @return Collection<int, Holding>
     *
     * @throws HoldingRefusedException when the holding was never remapped or is not the user's
     */
    public function reset(User $user, Holding $holding): Collection
    {
        $own = $this->owned($user, $holding);

        if ($own->original_security_id === null) {
            throw new HoldingRefusedException('That holding was not remapped.');
        }

        return DB::transaction(fn (): Collection => $this->move($user, $own->account_id, $own->security_id, $own->original_security_id, onlyOriginal: true));
    }

    private function owned(User $user, Holding $holding): Holding
    {
        return Holding::query()->forUser($user)->whereKey($holding->id)->first()
            ?? throw new HoldingRefusedException('That holding is not on one of your accounts.');
    }

    /**
     * @return Collection<int, Holding>
     */
    private function holdingsOf(int $accountId, int $securityId): Collection
    {
        return Holding::query()->where('account_id', $accountId)->where('security_id', $securityId)->orderBy('id')->get();
    }

    /**
     * @return Collection<int, Holding>
     */
    private function move(User $user, int $accountId, int $fromSecurityId, int $toSecurityId, bool $onlyOriginal): Collection
    {
        Account::query()->where('user_id', $user->id)->whereKey($accountId)->lockForUpdate()->firstOrFail(['id']);

        $ids = Holding::query()->where('account_id', $accountId)->whereIn('security_id', [$fromSecurityId, $toSecurityId])->orderBy('id')->lockForUpdate()->pluck('id')->all();

        $moving = Holding::query()
            ->whereIn('id', $ids)
            ->where('security_id', $fromSecurityId)
            ->when($onlyOriginal, fn ($query) => $query->where('original_security_id', $toSecurityId))
            ->orderBy('id')
            ->get();
        $existing = Holding::query()->whereIn('id', $ids)->where('security_id', $toSecurityId)->get()
            ->keyBy(fn (Holding $row): string => $row->date->toDateString().'|'.$row->currency);

        foreach ($moving as $row) {
            $key = $row->date->toDateString().'|'.$row->currency;
            $survivor = $existing->get($key);
            $origin = $onlyOriginal ? null : ($row->original_security_id ?? $fromSecurityId);
            $restored = $origin === null || $origin === $toSecurityId;

            if ($survivor === null) {
                $row->forceFill([
                    'security_id' => $toSecurityId,
                    'original_security_id' => $restored ? null : $origin,
                    'provider_locked' => ! $restored,
                ])->save();
                $existing->put($key, $row);

                continue;
            }

            $merged = $this->weightedCost($survivor, $row);

            $survivor->forceFill([
                'quantity' => Decimal::add($survivor->quantity, $row->quantity),
                'amount' => $survivor->amount + $row->amount,
                'cost_basis_per_share' => $merged,
                'cost_basis_source' => $merged === null ? null : CostBasisSource::Calculated,
                'cost_basis_locked' => false,
                'provider_locked' => $restored ? $survivor->provider_locked : true,
            ])->save();
            $row->delete();
        }

        $this->moveTrades($accountId, $fromSecurityId, $toSecurityId, $onlyOriginal);
        $this->refresh($user, $accountId, [$fromSecurityId, $toSecurityId]);

        return $this->holdingsOf($accountId, $toSecurityId);
    }

    /**
     * Brings the holdings of both securities back in step with their trades, in every currency either
     * has on the account, since the position of the new security now includes the moved trades.
     *
     * @param  list<int>  $securityIds
     */
    private function refresh(User $user, int $accountId, array $securityIds): void
    {
        foreach ($securityIds as $securityId) {
            $currencies = Trade::query()->where('account_id', $accountId)->where('security_id', $securityId)->distinct()->pluck('currency')
                ->merge(Holding::query()->where('account_id', $accountId)->where('security_id', $securityId)->distinct()->pluck('currency'))
                ->unique();

            foreach ($currencies as $currency) {
                $this->snapshotter->refresh($user, $accountId, $securityId, $currency);
            }
        }
    }

    private function moveTrades(int $accountId, int $fromSecurityId, int $toSecurityId, bool $onlyOriginal): void
    {
        $from = Security::query()->find($fromSecurityId);
        $to = Security::query()->find($toSecurityId);

        $trades = Trade::query()
            ->where('account_id', $accountId)
            ->where('security_id', $fromSecurityId)
            ->when($onlyOriginal, fn ($query) => $query->where('original_security_id', $toSecurityId))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($trades as $trade) {
            $origin = $onlyOriginal ? null : ($trade->original_security_id ?? $fromSecurityId);
            $renamed = $trade->name === $this->recorder->nameFor($trade->activity, $trade->quantity, $from)
                ? $this->recorder->nameFor($trade->activity, $trade->quantity, $to)
                : $trade->name;

            $trade->forceFill([
                'security_id' => $toSecurityId,
                'original_security_id' => $origin === $toSecurityId ? null : $origin,
                'name' => $renamed,
            ])->save();
        }
    }

    private function weightedCost(Holding $a, Holding $b): ?int
    {
        if ($a->cost_basis_per_share === null || $b->cost_basis_per_share === null) {
            return null;
        }

        $total = bcadd(Decimal::mul($a->quantity, $a->cost_basis_per_share), Decimal::mul($b->quantity, $b->cost_basis_per_share), 16);
        $quantity = Decimal::add($a->quantity, $b->quantity);

        return Decimal::isZero($quantity) ? null : Decimal::toCents(bcdiv($total, $quantity, 16));
    }
}
