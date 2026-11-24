<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\Enums\PlannedTransactionType;
use App\Enums\TransactionDirection;

/**
 * The type a plan found by detection starts with, chosen once from what the pattern is called and what it
 * charges. A credit is income. The type is never reclassified afterwards: the user owns it from then on.
 */
final class PlanTypeClassifier
{
    public const int FLAT_CARD_CHARGE_LIMIT_CENTS = 15000;

    private const array INSTALLMENT = ['AFTERPAY', 'ZIPPAY', 'ZIP PAY', 'ZIP CO', 'KLARNA', 'HUMM', 'LAYBUY', 'OPENPAY', 'SEZZLE', 'PAYPAL PAY IN'];

    private const array SUBSCRIPTION = ['NETFLIX', 'SPOTIFY', 'DISNEY', 'STAN.COM', 'STAN', 'BINGE', 'YOUTUBE', 'KAYO', 'FOXTEL', 'AUDIBLE', 'PRIME VIDEO', 'AMAZON PRIME', 'APPLE.COM', 'ICLOUD', 'GOOGLE ONE', 'GOOGLE *', 'MICROSOFT', 'ADOBE', 'DROPBOX', 'OPENAI', 'GITHUB', 'PARAMOUNT', 'HBO', 'TIDAL', 'DEEZER', 'NOTION', 'CANVA', 'ZOOM', 'SUBSCRIPTION'];

    private const array BILL = ['AGL', 'ORIGIN ENERGY', 'ENERGY', 'ELECTRIC', 'WATER', 'GAS', 'RENT', 'REAL ESTATE', 'INSURANCE', 'INSURE', 'NRMA', 'RACV', 'RAA', 'TELSTRA', 'OPTUS', 'VODAFONE', 'TPG', 'NBN', 'INTERNET', 'COUNCIL', 'RATES'];

    /**
     * @param  bool  $flatCardCharge  every charge is identical, on a credit card, and no more than the limit
     */
    public function classify(string $description, TransactionDirection $direction, bool $flatCardCharge = false): PlannedTransactionType
    {
        if ($direction === TransactionDirection::Credit) {
            return PlannedTransactionType::Income;
        }

        $name = mb_strtoupper($description);

        return match (true) {
            $this->contains($name, self::INSTALLMENT) => PlannedTransactionType::Installment,
            $this->contains($name, self::SUBSCRIPTION) => PlannedTransactionType::Subscription,
            $this->contains($name, self::BILL) => PlannedTransactionType::Bill,
            $flatCardCharge => PlannedTransactionType::Subscription,
            default => PlannedTransactionType::Bill,
        };
    }

    /** Subscriptions and installments are normally charged without the user paying by hand. */
    public function defaultAutopay(PlannedTransactionType $type): bool
    {
        return in_array($type, [PlannedTransactionType::Subscription, PlannedTransactionType::Installment], true);
    }

    /** @param  list<string>  $needles */
    private function contains(string $name, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u', $name) === 1) {
                return true;
            }
        }

        return false;
    }
}
