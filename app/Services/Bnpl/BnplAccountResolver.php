<?php

declare(strict_types=1);

namespace App\Services\Bnpl;

use App\Enums\BnplProvider;
use App\Enums\TransactionDirection;
use App\Enums\TransactionStatus;
use App\Models\BnplOrder;
use App\Models\Transaction;
use App\Models\User;
use RuntimeException;

/**
 * Picks the account a BNPL plan is drawn from. The plan must sit on the
 * account the instalments actually post to, or reconciliation (which matches
 * plans per account) never pairs them; the user's primary account is often
 * a savings account while the provider charges a credit card.
 */
final readonly class BnplAccountResolver
{
    /**
     * In order: the account of the provider's newest order, the account of
     * the latest posted instalment debit for that provider, the primary
     * account, the user's first account.
     *
     * @throws RuntimeException when the user has no account at all
     */
    public function resolve(User $user, BnplProvider $provider): int
    {
        $accountId = BnplOrder::query()
            ->where('user_id', $user->id)
            ->where('provider', $provider)
            ->whereNotNull('account_id')
            ->orderByDesc('id')
            ->value('account_id')
            ?? $this->latestInstalmentAccount($user, $provider)
            ?? $user->primary_account_id
            ?? $user->accounts()->orderBy('id')->value('id');

        if ($accountId === null) {
            throw new RuntimeException("User {$user->id} has no account to hold a {$provider->label()} plan.");
        }

        return (int) $accountId;
    }

    private function latestInstalmentAccount(User $user, BnplProvider $provider): ?int
    {
        $pattern = $this->bankDescriptionPattern($provider);

        if ($pattern === null) {
            return null;
        }

        $accountId = Transaction::query()
            ->where('user_id', $user->id)
            ->where('status', TransactionStatus::Posted)
            ->where('direction', TransactionDirection::Debit)
            ->where('description', 'like', $pattern)
            ->orderByDesc('post_date')
            ->orderByDesc('id')
            ->value('account_id');

        return $accountId === null ? null : (int) $accountId;
    }

    /**
     * SQL LIKE pattern that identifies the provider's instalments on a bank
     * statement; null when the provider has none known yet.
     */
    private function bankDescriptionPattern(BnplProvider $provider): ?string
    {
        return match ($provider) {
            BnplProvider::Paypal => '%PAYIN4%',
            default => null,
        };
    }
}
