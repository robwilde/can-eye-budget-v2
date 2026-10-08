<?php

declare(strict_types=1);

namespace App\Enums;

enum BnplProvider: string
{
    case Afterpay = 'afterpay';
    case Zip = 'zip';
    case Klarna = 'klarna';
    case Paypal = 'paypal';
    case Humm = 'humm';

    /**
     * The provider a bank narration names, by the same pattern the SQL side uses.
     */
    public static function fromBankDescription(string $description): ?self
    {
        $haystack = mb_strtoupper($description);

        foreach (self::cases() as $case) {
            $pattern = $case->bankDescriptionPattern();

            if ($pattern !== null && str_contains($haystack, mb_strtoupper(mb_trim($pattern, '%')))) {
                return $case;
            }
        }

        return null;
    }

    public function label(): string
    {
        return match ($this) {
            self::Afterpay => 'Afterpay',
            self::Zip => 'Zip',
            self::Klarna => 'Klarna',
            self::Paypal => 'PayPal',
            self::Humm => 'Humm',
        };
    }

    /**
     * SQL LIKE pattern that identifies this provider's instalments on a bank
     * statement; null when none is known yet.
     */
    public function bankDescriptionPattern(): ?string
    {
        return match ($this) {
            self::Afterpay => '%AFTERPAY%',
            self::Paypal => '%PAYIN4%',
            default => null,
        };
    }
}
