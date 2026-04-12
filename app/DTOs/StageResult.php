<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

final class StageResult extends Dto
{
    /**
     * @param  list<int>  $suggestionIds
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $stage,
        public readonly ?string $error = null,
        public readonly array $suggestionIds = [],
    ) {}
}
