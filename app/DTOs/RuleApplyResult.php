<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

/**
 * What applying one rule to a user's history did. Blocked is processed minus modified: rows the
 * rule matched but left as they were, because a lock, a higher rule's claim or an unchanged value
 * kept them.
 */
final class RuleApplyResult extends Dto
{
    public function __construct(
        public readonly int $processed = 0,
        public readonly int $modified = 0,
        public readonly int $handled = 0,
    ) {}

    public function blocked(): int
    {
        return $this->processed - $this->modified;
    }
}
