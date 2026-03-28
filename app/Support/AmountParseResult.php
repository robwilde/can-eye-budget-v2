<?php

declare(strict_types=1);

namespace App\Support;

final readonly class AmountParseResult
{
    public function __construct(
        public int $amount,
        public string $description,
    ) {}
}
