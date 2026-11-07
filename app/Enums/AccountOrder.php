<?php

declare(strict_types=1);

namespace App\Enums;

enum AccountOrder: string
{
    case NameAsc = 'name-asc';
    case NameDesc = 'name-desc';
    case BalanceAsc = 'balance-asc';
    case BalanceDesc = 'balance-desc';

    public function label(): string
    {
        return match ($this) {
            self::NameAsc => 'Name (A–Z)',
            self::NameDesc => 'Name (Z–A)',
            self::BalanceAsc => 'Balance (low to high)',
            self::BalanceDesc => 'Balance (high to low)',
        };
    }

    public function sortsByBalance(): bool
    {
        return $this === self::BalanceAsc || $this === self::BalanceDesc;
    }

    public function isDescending(): bool
    {
        return $this === self::NameDesc || $this === self::BalanceDesc;
    }
}
