<?php

declare(strict_types=1);

namespace App\Enums;

enum BankImportStatus: string
{
    case Pending = 'pending';
    case Parsing = 'parsing';
    case Previewing = 'previewing';
    case Importing = 'importing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Parsing => 'Parsing',
            self::Previewing => 'Previewing',
            self::Importing => 'Importing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
