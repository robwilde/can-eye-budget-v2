<?php

declare(strict_types=1);

namespace App\Enums;

enum CleanDescriptionSource: string
{
    case Manual = 'manual';
    case Rule = 'rule';
    case Feed = 'feed';
    case Brand = 'brand';
    case Derived = 'derived';

    public function rank(): int
    {
        return match ($this) {
            self::Derived => 1,
            self::Brand => 2,
            self::Feed => 3,
            self::Rule => 4,
            self::Manual => 5,
        };
    }

    public function isImportDerived(): bool
    {
        return in_array($this, [self::Feed, self::Brand, self::Derived], true);
    }

    public function yieldsTo(self $incoming): bool
    {
        return $this->isImportDerived() && $this->rank() <= $incoming->rank();
    }
}
