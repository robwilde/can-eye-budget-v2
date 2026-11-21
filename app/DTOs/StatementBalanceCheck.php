<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

/**
 * The outcome of comparing a statement's balances with the ledger. A statement that could not
 * be compared is not checked, and is never a mismatch.
 */
final class StatementBalanceCheck extends Dto
{
    /**
     * @param  list<string>  $failures  which comparisons differ: opening, closing or movement
     */
    public function __construct(
        public readonly bool $checked,
        public readonly array $failures = [],
    ) {}

    public static function notChecked(): self
    {
        return new self(false);
    }

    public function isMismatch(): bool
    {
        return $this->checked && $this->failures !== [];
    }
}
