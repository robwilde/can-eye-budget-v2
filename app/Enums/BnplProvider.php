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
}
