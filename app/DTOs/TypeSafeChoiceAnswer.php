<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

/** One TypeSafe Choice answer plus the call's model version and token usage. */
final class TypeSafeChoiceAnswer extends Dto
{
    /**
     * @param  array<string, float>  $probabilities  option id => probability, highest first
     */
    public function __construct(
        public readonly string $choice,
        public readonly array $probabilities,
        public readonly float $confidence,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
    ) {}

    /**
     * The option ids ranked by probability, best first.
     *
     * @return list<string>
     */
    public function ranked(): array
    {
        return array_map(strval(...), array_keys($this->probabilities));
    }

    /** Probability gap between the best and second-best option; 1.0 when there is only one. */
    public function margin(): float
    {
        $values = array_values($this->probabilities);

        return ($values[0] ?? 0.0) - ($values[1] ?? 0.0);
    }
}
