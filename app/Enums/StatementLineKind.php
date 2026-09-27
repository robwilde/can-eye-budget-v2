<?php

declare(strict_types=1);

namespace App\Enums;

enum StatementLineKind: string
{
    case Matched = 'matched';
    case StatementOnly = 'statement_only';
    case FeedOnly = 'feed_only';

    public function label(): string
    {
        return match ($this) {
            self::Matched => 'Matched',
            self::StatementOnly => 'Statement only',
            self::FeedOnly => 'Feed only',
        };
    }
}
