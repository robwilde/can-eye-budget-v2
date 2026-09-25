<?php

declare(strict_types=1);

namespace App\Services\MerchantBrands;

use App\Enums\TransactionDirection;
use App\Models\Transaction;
use App\Support\Recurring\MerchantSignature;

/**
 * Decides whether a transaction's descriptor may be sent to Context.dev.
 *
 * This is a privacy control first and a credit saver second: a false allow sends
 * a payee's name to a third party. It therefore only admits card-style merchant
 * debits and refuses anything that looks like money moving between people or
 * accounts, bank charges, or a payment aggregator whose key names no merchant.
 *
 * Pure: no I/O, so the decision table is pinned by a unit-test dataset.
 */
final class DescriptorGate
{
    /**
     * Words that mark person-to-person or account movements, bank charges and
     * income. Matched on word boundaries, so FEE does not catch COFFEE.
     */
    private const string DENY_PATTERN = '/\b(TRANSFER|TFR|XFER|BPAY|PAYID|PAY ?ID|OSKO|NPP|PAY ANYONE|PAYMENT (TO|FROM)|INTERNET BANKING|NETBANK|ATM|CASH ?OUT|WITHDRAWAL|DEPOSIT|FEE|FEES|INTEREST|SALARY|WAGES|PAYROLL|PAY CYCLE|DIRECT CREDIT|REFUND|REVERSAL|LOAN|MORTGAGE|REPAYMENT|CREDIT CARD|CHEQUE)\b/u';

    /**
     * Merchant keys that name a payment rail rather than a merchant. "PAYPAL" alone
     * could be any shop; "PAYPAL STEAM" names one and is allowed.
     */
    private const array AGGREGATOR_KEYS = [
        'PAYPAL', 'SQ', 'SQUARE', 'AFTERPAY', 'ZIP', 'ZIPPAY', 'ZIP PAY', 'STRIPE',
        'KLARNA', 'HUMM', 'LATITUDE', 'EFTPOS', 'VISA', 'MASTERCARD', 'APPLE PAY', 'GOOGLE PAY',
    ];

    /** Card-network and wallet words that prefix a descriptor without naming the payee. */
    private const array NETWORK_TOKENS = ['VISA', 'MASTERCARD', 'MC', 'EFTPOS', 'ANDROID', 'APPLE', 'GOOGLE', 'PAY', 'DEBIT', 'CARD'];

    /** Payment rails whose name, alone, says nothing about which shop was paid. */
    private const array RAIL_TOKENS = ['PAYPAL', 'SQ', 'SQUARE', 'AFTERPAY', 'ZIP', 'ZIPPAY', 'STRIPE', 'KLARNA', 'HUMM', 'LATITUDE'];

    /** Rail product codes and filler that still name no merchant (PayPal "Pay in 4"). */
    private const array RAIL_FILLER_TOKENS = ['PYPL', 'PAYIN4', 'INTERNET', 'ONLINE', 'PAYMENT', 'PURCHASE', 'FRGN'];

    /**
     * Words that mark a short all-letter descriptor as a business rather than a
     * person's name. Without one, "COLES SUPERMARKET" and "JOHN SMITH" have the
     * same shape, so the gate refuses rather than guesses.
     */
    private const array MERCHANT_CUE_TOKENS = [
        'PTY', 'LTD', 'LIMITED', 'INC', 'CO', 'CORP', 'GROUP', 'AUSTRALIA', 'AUST',
        'SUPERMARKET', 'SUPERMARKETS', 'MARKET', 'MARKETS', 'STORE', 'STORES', 'SHOP', 'MART',
        'CAFE', 'COFFEE', 'RESTAURANT', 'BAR', 'HOTEL', 'PIZZA', 'BAKERY', 'KITCHEN',
        'PHARMACY', 'CHEMIST', 'WAREHOUSE', 'SERVICE', 'STATION', 'FUEL', 'PETROL', 'EXPRESS',
        'INSURANCE', 'ENERGY', 'TELECOM', 'MOBILE', 'SUBSCRIPTION', 'MEMBERSHIP', 'CLINIC', 'DENTAL',
    ];

    /** A name-only descriptor is at most this many tokens ("JANE A CITIZEN"). */
    private const int NAME_ONLY_MAX_TOKENS = 3;

    private const int MIN_LENGTH = 3;

    private const int MAX_LENGTH = 500;

    public function allows(Transaction $transaction): bool
    {
        return $this->sendableDescriptor($transaction) !== null;
    }

    /**
     * The text that may leave the app for this transaction, or null when nothing
     * may. Never the raw description: statements carry card masks (#8357), auth
     * codes, policy and member numbers. MerchantSignature already drops those
     * code tokens, so its output is sent, and anything still holding a 4+ digit
     * run (its all-codes fallback returns the raw text) is refused outright.
     */
    public function sendableDescriptor(Transaction $transaction): ?string
    {
        if ($transaction->transfer_pair_id !== null || $transaction->folded_into_transaction_id !== null) {
            return null;
        }

        // Credits are salaries, refunds, interest and person-to-person receipts:
        // the income side carries the most personal payee text and no merchant.
        if ($transaction->direction !== TransactionDirection::Debit) {
            return null;
        }

        $key = $transaction->merchant_key ?? $transaction->resolveMerchantKey();

        if ($key === Transaction::UNKNOWN_MERCHANT_KEY || in_array($key, self::AGGREGATOR_KEYS, true)) {
            return null;
        }

        $raw = mb_trim((string) $transaction->description);

        if ($raw === '' || mb_strlen($raw) > self::MAX_LENGTH || preg_match(self::DENY_PATTERN, mb_strtoupper($raw)) === 1) {
            return null;
        }

        $redacted = MerchantSignature::for($raw);

        if (mb_strlen($redacted) < self::MIN_LENGTH || preg_match('/\d{4,}/', $redacted) === 1) {
            return null;
        }

        $tokens = $this->payeeTokens($redacted);

        if ($this->isRailOnly($tokens)) {
            return null;
        }

        // A card-network or payment-rail prefix means a card or rail paid the payee,
        // and those cannot pay a person directly, so only unmarked descriptors get
        // the name-shape check.
        $viaCardOrRail = count($tokens) !== count(preg_split('/\s+/', $redacted) ?: [])
            || ($tokens !== [] && in_array($tokens[0], self::RAIL_TOKENS, true));

        if (! $viaCardOrRail && $this->looksLikeAName($tokens)) {
            return null;
        }

        return $redacted;
    }

    /**
     * The descriptor's tokens with leading card-network/wallet words removed, so
     * "VISA ANDROID PAY JOHN SMITH" is judged on "JOHN SMITH".
     *
     * @return list<string>
     */
    private function payeeTokens(string $redacted): array
    {
        $tokens = preg_split('/\s+/', $redacted) ?: [];

        while ($tokens !== [] && in_array(mb_ltrim($tokens[0], '-'), self::NETWORK_TOKENS, true)) {
            array_shift($tokens);
        }

        return array_map(static fn (string $t): string => mb_trim($t, '-*'), $tokens);
    }

    /**
     * "PAYPAL PYPL PAYIN4 AU" or "AFTERPAY AFTERPAY.COM AU": a rail followed only by
     * its own name, domain, product code or a country code names no merchant.
     *
     * @param  list<string>  $tokens
     */
    private function isRailOnly(array $tokens): bool
    {
        if ($tokens === [] || ! in_array($tokens[0], self::RAIL_TOKENS, true)) {
            return false;
        }

        $rail = $tokens[0];

        foreach (array_slice($tokens, 1) as $token) {
            $isFiller = in_array($token, self::RAIL_TOKENS, true)
                || in_array($token, self::RAIL_FILLER_TOKENS, true)
                || str_contains($token, $rail)
                || preg_match('/^[A-Z]{2}$/', $token) === 1;

            if (! $isFiller) {
                return false;
            }
        }

        return true;
    }

    /**
     * Short, letters-only, and no business word: on an unmarked descriptor this could
     * be a person paid by pay-anyone or direct debit. Refused, because a false allow
     * sends a name to a third party; plainly named merchants are the accepted cost.
     *
     * @param  list<string>  $tokens
     */
    private function looksLikeAName(array $tokens): bool
    {
        if ($tokens === [] || count($tokens) > self::NAME_ONLY_MAX_TOKENS) {
            return false;
        }

        foreach ($tokens as $token) {
            if (preg_match("/^[A-Z][A-Z'\\-]*$/u", $token) !== 1 || in_array($token, self::MERCHANT_CUE_TOKENS, true)) {
                return false;
            }
        }

        return true;
    }
}
