<?php

declare(strict_types=1);

namespace App\Support\Rules;

final class RuleAiCategorisation
{
    /**
     * Whether the AI categorise action may be offered and run: the feature flag is on and a
     * TypeSafe key is configured.
     */
    public static function available(): bool
    {
        return config('budget.rule_ai_categorisation') === true && filled(config('services.typesafe.api_key'));
    }
}
