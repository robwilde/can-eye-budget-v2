<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

/**
 * One child transaction of a split. The amount is a positive magnitude in cents: the child
 * takes the parent's direction and sign. A blank name falls back to the parent's.
 */
final class TransactionSplitLine extends Dto
{
    public function __construct(
        public readonly int $amount,
        public readonly ?int $categoryId = null,
        public readonly ?string $name = null,
        public readonly ?string $notes = null,
        public readonly bool $excluded = false,
    ) {}
}
