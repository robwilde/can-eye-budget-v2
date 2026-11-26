<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\AssistantMode;
use App\Enums\RegistrationMode;
use App\Models\InstanceSetting;
use App\Services\InstanceSettings;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Instance admins edit the self-hosting settings: registration mode, provider keys, LLM settings, assistant mode,
 * pending transactions and the daily auto-sync. A provider key is write-only: it is never put back into the page, only
 * a masked hint is shown, and a blank key field keeps what is saved. Every method re-checks the admin gate.
 */
final class AdminSettings extends Component
{
    public const int MAX_PROMPT_LENGTH = 4000;

    public string $registrationMode = '';

    public string $openaiKey = '';

    public string $anthropicKey = '';

    public string $contextDevKey = '';

    public string $llmProvider = '';

    public string $llmModel = '';

    public string $llmEndpoint = '';

    public string $llmTokenBudget = '';

    public string $assistantMode = '';

    public string $systemPrompt = '';

    public string $includePending = '';

    public string $autoSync = 'default';

    public string $autoSyncTime = InstanceSettings::DEFAULT_SYNC_TIME;

    public function mount(InstanceSettings $settings): void
    {
        Gate::authorize('admin');

        $this->registrationMode = $settings->stored(InstanceSetting::REGISTRATION_MODE) ?? '';
        $this->llmProvider = $settings->stored(InstanceSetting::LLM_PROVIDER) ?? '';
        $this->llmModel = $settings->stored(InstanceSetting::LLM_MODEL) ?? '';
        $this->llmEndpoint = $settings->stored(InstanceSetting::LLM_ENDPOINT) ?? '';
        $this->llmTokenBudget = $settings->stored(InstanceSetting::LLM_TOKEN_BUDGET) ?? '';
        $this->assistantMode = $settings->stored(InstanceSetting::ASSISTANT_MODE) ?? '';
        $this->systemPrompt = $settings->systemPrompt() ?? '';
        $this->includePending = $settings->stored(InstanceSetting::INCLUDE_PENDING) ?? '';
        $this->autoSync = match ($settings->autoSync()) {
            true => 'on',
            false => 'off',
            null => 'default',
        };
        $this->autoSyncTime = $settings->autoSyncTime();
    }

    public function save(InstanceSettings $settings): void
    {
        Gate::authorize('admin');

        $this->resetErrorBag();
        $this->autoSyncTime = mb_trim($this->autoSyncTime);

        if ($this->autoSyncTime !== '' && preg_match(InstanceSettings::SYNC_TIME_PATTERN, $this->autoSyncTime) !== 1) {
            $this->addError('autoSyncTime', __('Invalid sync time. Use a time from 00:00 to 23:59.'));

            return;
        }

        $this->validate([
            'registrationMode' => ['nullable', Rule::enum(RegistrationMode::class)],
            'openaiKey' => ['nullable', 'string', 'max:500'],
            'anthropicKey' => ['nullable', 'string', 'max:500'],
            'contextDevKey' => ['nullable', 'string', 'max:500'],
            'llmProvider' => ['nullable', Rule::in(['openai', 'anthropic'])],
            'llmModel' => ['nullable', 'string', 'max:100'],
            'llmEndpoint' => ['nullable', 'url:http,https', 'max:255'],
            'llmTokenBudget' => ['nullable', 'integer', 'min:64', 'max:200000'],
            'assistantMode' => ['nullable', Rule::enum(AssistantMode::class)],
            'systemPrompt' => ['nullable', 'string', 'max:'.self::MAX_PROMPT_LENGTH],
            'includePending' => ['nullable', Rule::in(['1', '0'])],
            'autoSync' => ['required', Rule::in(['default', 'on', 'off'])],
        ]);

        $values = [
            InstanceSetting::REGISTRATION_MODE => $this->registrationMode,
            InstanceSetting::LLM_PROVIDER => $this->llmProvider,
            InstanceSetting::LLM_MODEL => mb_trim($this->llmModel),
            InstanceSetting::LLM_ENDPOINT => mb_trim($this->llmEndpoint),
            InstanceSetting::LLM_TOKEN_BUDGET => mb_trim($this->llmTokenBudget),
            InstanceSetting::ASSISTANT_MODE => $this->assistantMode,
            InstanceSetting::SYSTEM_PROMPT => mb_trim($this->systemPrompt),
            InstanceSetting::INCLUDE_PENDING => $this->includePending,
            InstanceSetting::AUTO_SYNC => match ($this->autoSync) {
                'on' => '1',
                'off' => '0',
                default => null,
            },
            InstanceSetting::AUTO_SYNC_TIME => $this->autoSync === 'on' ? ($this->autoSyncTime === '' ? InstanceSettings::DEFAULT_SYNC_TIME : $this->autoSyncTime) : null,
        ];

        foreach ([
            InstanceSetting::OPENAI_API_KEY => $this->openaiKey,
            InstanceSetting::ANTHROPIC_API_KEY => $this->anthropicKey,
            InstanceSetting::CONTEXT_DEV_API_KEY => $this->contextDevKey,
        ] as $key => $typed) {
            if (mb_trim($typed) !== '') {
                $values[$key] = mb_trim($typed);
            }
        }

        $settings->save($values);

        $this->reset('openaiKey', 'anthropicKey', 'contextDevKey');
        $this->autoSyncTime = $settings->autoSyncTime();

        Flux::toast(text: __('Settings saved.'), variant: 'success');
    }

    public function clearKey(string $provider, InstanceSettings $settings): void
    {
        Gate::authorize('admin');

        $key = match ($provider) {
            'openai' => InstanceSetting::OPENAI_API_KEY,
            'anthropic' => InstanceSetting::ANTHROPIC_API_KEY,
            'context_dev' => InstanceSetting::CONTEXT_DEV_API_KEY,
            default => null,
        };

        if ($key === null) {
            return;
        }

        $settings->save([$key => null]);
    }

    public function render(InstanceSettings $settings): View
    {
        Gate::authorize('admin');

        return view('livewire.admin-settings', [
            'hints' => [
                'openai' => $settings->secretHint(InstanceSetting::OPENAI_API_KEY),
                'anthropic' => $settings->secretHint(InstanceSetting::ANTHROPIC_API_KEY),
                'context_dev' => $settings->secretHint(InstanceSetting::CONTEXT_DEV_API_KEY),
            ],
            'defaults' => $settings->environmentDefaults(),
            'registrationModes' => RegistrationMode::cases(),
            'assistantModes' => AssistantMode::cases(),
        ]);
    }
}
