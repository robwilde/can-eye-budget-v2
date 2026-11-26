<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AssistantMode;
use App\Enums\RegistrationMode;
use App\Models\InstanceSetting;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The instance's settings: what an admin saved, falling back to the environment config for anything not saved. The saved
 * values are read once per request or job (the service is scoped) and again after a save. A database without the table
 * yet, as during a first migrate, reads as nothing saved.
 */
final class InstanceSettings
{
    public const string DEFAULT_SYNC_TIME = '03:00';

    public const string SYNC_TIME_PATTERN = '/\A([01]\d|2[0-3]):[0-5]\d\z/';

    /** @var array<string, string|null>|null */
    private ?array $values = null;

    /** @var list<string> */
    private array $unreadable = [];

    public function registrationMode(): RegistrationMode
    {
        $stored = $this->value(InstanceSetting::REGISTRATION_MODE);

        if ($stored !== null) {
            return RegistrationMode::tryFrom($stored) ?? RegistrationMode::Closed;
        }

        $mode = config('budget.registration_mode');

        return is_string($mode) ? (RegistrationMode::tryFrom($mode) ?? RegistrationMode::Closed) : RegistrationMode::Closed;
    }

    public function assistantMode(): AssistantMode
    {
        $stored = $this->value(InstanceSetting::ASSISTANT_MODE);

        if ($stored !== null) {
            return AssistantMode::tryFrom($stored) ?? AssistantMode::Off;
        }

        return config('budget.ai_assistant') === true ? AssistantMode::BuiltIn : AssistantMode::McpOnly;
    }

    /**
     * Whether the assistant's tools are served to other clients (MCP): unless an admin switched the assistant off. Until
     * an admin chooses, the chat follows the environment flag and the tools stay available.
     */
    public function toolsEnabled(): bool
    {
        return $this->assistantMode() !== AssistantMode::Off;
    }

    public function llmProvider(): string
    {
        return $this->value(InstanceSetting::LLM_PROVIDER) ?? (string) config('services.ai.provider', 'openai');
    }

    public function llmModel(): string
    {
        return $this->value(InstanceSetting::LLM_MODEL) ?? (string) config('services.ai.model', '');
    }

    public function llmEndpoint(): ?string
    {
        $endpoint = $this->value(InstanceSetting::LLM_ENDPOINT) ?? config('services.ai.base_url');

        return is_string($endpoint) && $endpoint !== '' ? $endpoint : null;
    }

    public function llmTokenBudget(): int
    {
        return $this->savedTokenBudget() ?? config()->integer('services.ai.max_tokens', 2048);
    }

    /**
     * The budget an admin saved, or null when none was: only a saved budget is sent to providers that do not require one.
     */
    public function savedTokenBudget(): ?int
    {
        $stored = $this->value(InstanceSetting::LLM_TOKEN_BUDGET);

        return $stored !== null && ctype_digit($stored) ? (int) $stored : null;
    }

    /**
     * What an admin saved for a key, or null: the page fills its fields from this so a save never copies the
     * environment into the database.
     */
    public function stored(string $key): ?string
    {
        return $this->value($key);
    }

    /**
     * What the environment alone would give, for showing next to a field that is not set.
     *
     * @return array<string, string>
     */
    public function environmentDefaults(): array
    {
        $registration = config('budget.registration_mode');
        $endpoint = config('services.ai.base_url');

        return [
            InstanceSetting::REGISTRATION_MODE => is_string($registration) ? $registration : RegistrationMode::Closed->value,
            InstanceSetting::LLM_PROVIDER => (string) config('services.ai.provider', 'openai'),
            InstanceSetting::LLM_MODEL => (string) config('services.ai.model', ''),
            InstanceSetting::LLM_ENDPOINT => is_string($endpoint) ? $endpoint : '',
            InstanceSetting::LLM_TOKEN_BUDGET => (string) config()->integer('services.ai.max_tokens', 2048),
            InstanceSetting::ASSISTANT_MODE => (config('budget.ai_assistant') === true ? AssistantMode::BuiltIn : AssistantMode::McpOnly)->value,
            InstanceSetting::INCLUDE_PENDING => config('services.redbark.include_pending') ? '1' : '0',
        ];
    }

    public function systemPrompt(): ?string
    {
        return $this->value(InstanceSetting::SYSTEM_PROMPT);
    }

    public function openaiApiKey(): ?string
    {
        return $this->secret(InstanceSetting::OPENAI_API_KEY, 'services.openai.key');
    }

    public function anthropicApiKey(): ?string
    {
        return $this->secret(InstanceSetting::ANTHROPIC_API_KEY, 'services.anthropic.key');
    }

    public function contextDevApiKey(): ?string
    {
        return $this->secret(InstanceSetting::CONTEXT_DEV_API_KEY, 'services.context_dev.api_key');
    }

    /**
     * The key of the selected provider, or null when it has none.
     */
    public function llmApiKey(): ?string
    {
        return match ($this->llmProvider()) {
            'openai' => $this->openaiApiKey(),
            'anthropic' => $this->anthropicApiKey(),
            default => null,
        };
    }

    public function includePending(): bool
    {
        $stored = $this->value(InstanceSetting::INCLUDE_PENDING);

        return $stored !== null ? $stored === '1' : (bool) config('services.redbark.include_pending');
    }

    /**
     * True or false when an admin chose, null when the default schedule (every six hours) applies.
     */
    public function autoSync(): ?bool
    {
        $stored = $this->value(InstanceSetting::AUTO_SYNC);

        return $stored === null ? null : $stored === '1';
    }

    public function autoSyncTime(): string
    {
        $stored = $this->value(InstanceSetting::AUTO_SYNC_TIME);

        return $stored !== null && preg_match(self::SYNC_TIME_PATTERN, $stored) === 1 ? $stored : self::DEFAULT_SYNC_TIME;
    }

    /**
     * Where a secret comes from and its last four characters, never the value: 'saved', 'environment', or 'unreadable' for
     * a saved key the app key can no longer decrypt.
     *
     * @return array{source: string, hint: string}|null
     */
    public function secretHint(string $key): ?array
    {
        $configKeys = [
            InstanceSetting::OPENAI_API_KEY => 'services.openai.key',
            InstanceSetting::ANTHROPIC_API_KEY => 'services.anthropic.key',
            InstanceSetting::CONTEXT_DEV_API_KEY => 'services.context_dev.api_key',
        ];

        $this->values();

        if (in_array($key, $this->unreadable, true)) {
            return ['source' => 'unreadable', 'hint' => ''];
        }

        $saved = $this->value($key);
        $fromEnvironment = config($configKeys[$key] ?? '');
        $secret = $saved ?? (is_string($fromEnvironment) && $fromEnvironment !== '' ? $fromEnvironment : null);

        if ($secret === null) {
            return null;
        }

        return ['source' => $saved !== null ? 'saved' : 'environment', 'hint' => '…'.mb_substr($secret, -4)];
    }

    /**
     * Saves the given values in one transaction; a null or empty value removes the setting so the environment applies.
     *
     * @param  array<string, string|null>  $values
     */
    public function save(array $values): void
    {
        $write = function () use ($values): void {
            DB::transaction(function () use ($values): void {
                foreach ($values as $key => $value) {
                    InstanceSetting::put($key, $value);
                }
            });
        };

        try {
            $write();
        } catch (UniqueConstraintViolationException) {
            $write();
        }

        $this->flush();
    }

    public function flush(): void
    {
        $this->values = null;
        $this->unreadable = [];
    }

    private function secret(string $key, string $configKey): ?string
    {
        $stored = $this->value($key);

        if ($stored !== null) {
            return $stored;
        }

        $fallback = config($configKey);

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    private function value(string $key): ?string
    {
        return $this->values()[$key] ?? null;
    }

    /**
     * @return array<string, string|null>
     */
    private function values(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        try {
            $values = [];

            foreach (InstanceSetting::query()->get() as $setting) {
                $values[$setting->key] = $setting->value;

                if ($setting->value === null && is_string($setting->getRawOriginal('value')) && $setting->getRawOriginal('value') !== '') {
                    $this->unreadable[] = $setting->key;
                    Log::warning('Instance setting could not be decrypted', ['key' => $setting->key]);
                }
            }
        } catch (QueryException) {
            $values = [];
        }

        return $this->values = $values;
    }
}
