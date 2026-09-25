<?php

declare(strict_types=1);

namespace App\Services\MerchantBrands;

use App\Models\Transaction;
use App\Models\User;

/**
 * Picks the row whose descriptor stands for a merchant key in a brand lookup.
 * Shared by the sweep and the lookup so a key the gate refuses on every recent
 * row is never queued, never mind paid for.
 */
final readonly class RepresentativeTransaction
{
    /** Recent rows considered when picking one the gate will let through. */
    private const int CANDIDATE_ROWS = 10;

    public function __construct(private DescriptorGate $gate) {}

    /** Newest of the user's last CANDIDATE_ROWS rows for the key that may be sent, or null when none may. */
    public function for(User $user, string $merchantKey): ?Transaction
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('merchant_key', $merchantKey)
            ->latest('post_date')
            ->latest('id')
            ->limit(self::CANDIDATE_ROWS)
            ->get()
            ->first(fn (Transaction $transaction): bool => $this->gate->allows($transaction));
    }
}
