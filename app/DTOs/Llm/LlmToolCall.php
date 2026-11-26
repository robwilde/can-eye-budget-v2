<?php

declare(strict_types=1);

namespace App\DTOs\Llm;

use Spatie\LaravelData\Dto;

final class LlmToolCall extends Dto
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments = [],
    ) {}
}
