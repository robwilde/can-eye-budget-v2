<?php

declare(strict_types=1);

namespace App\Enums;

enum TransactionSource: string
{
    case Manual = 'manual';
    case Basiq = 'basiq';
    case Planned = 'planned';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Basiq => 'Basiq',
            self::Planned => 'Planned',
        };
    }
}
