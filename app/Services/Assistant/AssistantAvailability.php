<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Services\InstanceSettings;

final readonly class AssistantAvailability
{
    public function __construct(private InstanceSettings $settings) {}

    public function provider(): string
    {
        return $this->settings->llmProvider();
    }

    public function hasKey(): bool
    {
        return $this->settings->llmApiKey() !== null;
    }

    /**
     * Whether the in-app chat is offered: the assistant mode is the built-in chat.
     */
    public function chatEnabled(): bool
    {
        return $this->settings->assistantMode()->chatEnabled();
    }

    /**
     * On only when the chat is enabled, the selected provider is one we speak to, and it has a key.
     */
    public function available(): bool
    {
        return $this->chatEnabled()
            && in_array($this->provider(), ['openai', 'anthropic'], true)
            && $this->hasKey();
    }
}
