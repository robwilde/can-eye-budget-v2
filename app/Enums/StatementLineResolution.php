<?php

declare(strict_types=1);

namespace App\Enums;

enum StatementLineResolution: string
{
    case Linked = 'linked';
    case Imported = 'imported';
    case Ignored = 'ignored';

    public function label(): string
    {
        return match ($this) {
            self::Linked => 'Linked',
            self::Imported => 'Imported',
            self::Ignored => 'Ignored',
        };
    }
}
