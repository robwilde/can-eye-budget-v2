<?php

declare(strict_types=1);

namespace App\Support\Transactions;

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use Illuminate\Validation\ValidationException;

/**
 * Keeps a transaction's currency and optional exchange rate valid on every write: the currency
 * defaults to the account's, and a rate must be finite and above zero. Amounts stay in cents in
 * the transaction's own currency; the rate only records how it converts.
 */
final class TransactionCurrency
{
    public static function apply(Transaction $transaction): void
    {
        $attributes = $transaction->getAttributes();
        $currency = $attributes['currency'] ?? null;
        $rate = $attributes['exchange_rate'] ?? null;
        $accountCurrency = null;

        if (! is_string($currency) || mb_trim($currency) === '' || $rate !== null) {
            $accountCurrency = self::accountCurrency($transaction->user_id, $transaction->account_id);
        }

        if (! is_string($currency) || mb_trim($currency) === '') {
            $currency = $accountCurrency ?? 'AUD';
        }

        $currency = mb_strtoupper(mb_trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw ValidationException::withMessages(['currency' => 'The currency must be a three-letter code.']);
        }

        $transaction->currency = $currency;

        if ($rate === null) {
            return;
        }

        $rounded = is_numeric($rate) ? round((float) $rate, ExchangeRate::RATE_SCALE) : null;

        if ($rounded === null || ! ExchangeRate::isUsableRate($rounded)) {
            throw ValidationException::withMessages(['exchange_rate' => 'The exchange rate must be a finite number above zero.']);
        }

        $transaction->setAttribute('exchange_rate', $currency === $accountCurrency ? null : ExchangeRate::storedRate($rounded));
    }

    /**
     * Whether a new version moved to another account should take that account's currency: only
     * when the caller did not choose a currency and the parent's currency was its account's own.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function followsAccountChange(Transaction $parent, array $overrides): bool
    {
        return array_key_exists('account_id', $overrides)
            && (int) $overrides['account_id'] !== $parent->account_id
            && ! array_key_exists('currency', $overrides)
            && $parent->currency === self::accountCurrency($parent->user_id, $parent->account_id);
    }

    /**
     * A rate converts the transaction's currency into its account's, so a version moved to an
     * account in another currency cannot carry the old rate unless the caller supplies a new one.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function dropsStaleRate(Transaction $parent, array $overrides): bool
    {
        return array_key_exists('account_id', $overrides)
            && (int) $overrides['account_id'] !== $parent->account_id
            && ! array_key_exists('exchange_rate', $overrides)
            && self::accountCurrency($parent->user_id, $parent->account_id) !== self::accountCurrency($parent->user_id, (int) $overrides['account_id']);
    }

    private static function accountCurrency(int $userId, int $accountId): ?string
    {
        $currency = Account::query()->where('user_id', $userId)->whereKey($accountId)->value('currency');

        return is_string($currency) ? $currency : null;
    }
}
