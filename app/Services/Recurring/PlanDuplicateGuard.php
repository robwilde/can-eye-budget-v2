<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\Enums\TransactionDirection;
use App\Models\Merchant;
use App\Models\MerchantAlias;
use App\Models\PlannedTransaction;
use App\Models\User;

/**
 * Whether the user already has a plan for the same bill: same account, direction and amount, and the
 * same merchant or the same description ignoring case. A different amount is a different plan, so a
 * biller can have more than one price tier.
 */
final class PlanDuplicateGuard
{
    public function exists(User $user, int $accountId, int $amount, TransactionDirection $direction, ?int $merchantId, string $description, ?int $exceptPlanId = null, ?string $merchantKey = null): bool
    {
        $description = mb_strtolower(mb_trim($description));
        $merchantKeys = $this->merchantKeys($user, $merchantId, $merchantKey);

        if ($description === '' && $merchantKeys === []) {
            return false;
        }

        return PlannedTransaction::query()
            ->where('user_id', $user->id)
            ->visible()
            ->where('account_id', $accountId)
            ->when($exceptPlanId !== null, fn ($query) => $query->whereKeyNot($exceptPlanId))
            ->whereNull('transfer_to_account_id')
            ->where('direction', $direction->value)
            ->where('amount', abs($amount))
            ->where(fn ($query) => $query
                ->when($merchantKeys !== [], fn ($match) => $match->orWhereIn('merchant_key', $merchantKeys))
                ->when($description !== '', fn ($match) => $match->orWhereRaw('LOWER(description) = ?', [$description])))
            ->exists();
    }

    /**
     * The merchant's own key and the keys of merchants merged into it, which plans may still carry. A plan
     * that stores only a key (no merchant id at hand) resolves to its merchant the same way, so an old merged
     * key and the surviving merchant's key count as one.
     *
     * @return list<string>
     */
    public function merchantKeys(User $user, ?int $merchantId, ?string $merchantKey = null): array
    {
        if ($merchantId === null && $merchantKey !== null) {
            $merchantId = Merchant::query()->where('user_id', $user->id)->where('merchant_key', $merchantKey)->value('id')
                ?? MerchantAlias::query()->where('user_id', $user->id)->where('merchant_key', $merchantKey)->value('merchant_id');

            if ($merchantId === null) {
                return [$merchantKey];
            }
        }

        if ($merchantId === null) {
            return [];
        }

        $own = Merchant::query()->where('user_id', $user->id)->whereKey($merchantId)->value('merchant_key');

        if ($own === null) {
            return [];
        }

        $merged = MerchantAlias::query()->where('user_id', $user->id)->where('merchant_id', $merchantId)->pluck('merchant_key')->all();

        return array_values(array_unique([$own, ...$merged]));
    }
}
