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

        return $redacted;
    }
}
