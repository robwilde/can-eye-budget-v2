<?php

declare(strict_types=1);

namespace App\Services\Assistant;

final readonly class AssistantAvailability
{
    public function provider(): string
    {
        return (string) config('services.ai.provider', 'openai');
    }

    public function hasKey(): bool
    {
        $key = config('services.'.$this->provider().'.key');

        return is_string($key) && $key !== '';
    }

    /**
     * On only when the deployment flag is set, the selected provider is one we speak to, and it has a key.
     */
    public function available(): bool
    {
        return config('budget.ai_assistant') === true
            && in_array($this->provider(), ['openai', 'anthropic'], true)
            && $this->hasKey();
    }
}
