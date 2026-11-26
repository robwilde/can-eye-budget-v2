<?php

declare(strict_types=1);

namespace App\DTOs\Llm;

use Spatie\LaravelData\Dto;

final class LlmResponse extends Dto
{
    /**
     * @param  list<LlmToolCall>  $toolCalls
     */
    public function __construct(
        public readonly string $content,
        public readonly array $toolCalls,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly string $model,
    ) {}
}
