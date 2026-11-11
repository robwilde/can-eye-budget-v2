<?php

declare(strict_types=1);

namespace App\Services\Merchants;

use App\Models\Merchant;
use App\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * The merchant key a transaction's Payee and MerchantBrand are looked up under: the key of
 * the merchant it is linked to, else its own stored key. A merged row keeps its derived
 * source key but points at the target merchant, whose key the Payee and brand moved to.
 */
final class MerchantKeyResolver
{
    /**
     * @param  Collection<int, Transaction>  $rows
     * @return array<int, string> the lookup key per transaction id
     */
    public function keysFor(int $userId, Collection $rows): array
    {
        $merchantKeys = Merchant::query()
            ->where('user_id', $userId)
            ->whereIn('id', $rows->pluck('merchant_id')->filter()->unique()->values())
            ->pluck('merchant_key', 'id');

        return $rows
            ->mapWithKeys(fn (Transaction $row): array => [
                $row->id => $merchantKeys->get($row->merchant_id) ?? $row->merchant_key ?? $row->resolveMerchantKey(),
            ])
            ->all();
    }

    public function keyFor(int $userId, Transaction $row): string
    {
        return $this->keysFor($userId, new Collection([$row]))[$row->id];
    }
}
