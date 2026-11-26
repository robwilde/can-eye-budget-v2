<?php

declare(strict_types=1);

namespace App\Services\Assistant;

final readonly class LlmCostCalculator
{
    /**
     * Cost in integer cents from the configured per-model prices (cents per million tokens, prompt and
     * completion); a dated snapshot name such as gpt-4o-mini-2024-07-18 uses its base model's price; a model without a price costs nothing here.
     */
    public function cents(string $model, int $promptTokens, int $completionTokens): int
    {
        $prices = (array) config('services.ai.prices', []);
        $price = $prices[$model] ?? null;

        foreach ($prices as $name => $candidate) {
            if ($price === null && preg_match('/^'.preg_quote((string) $name, '/').'-\d{4}-?\d{2}-?\d{2}$/', $model) === 1) {
                $price = $candidate;
            }
        }

        if (! is_array($price)) {
            return 0;
        }

        $cents = ($promptTokens * (float) ($price['prompt'] ?? 0) + $completionTokens * (float) ($price['completion'] ?? 0)) / 1_000_000;

        return (int) ceil($cents);
    }
}
