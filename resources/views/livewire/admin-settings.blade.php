<div class="mx-auto max-w-3xl space-y-8" data-test="admin-settings">
    <div class="space-y-2">
        <flux:heading size="xl">{{ __('Self-hosting settings') }}</flux:heading>
        <flux:text>{{ __('Saved here, these settings win over the environment. Clear a field to go back to the environment value. Keys are never shown again after saving.') }}</flux:text>
    </div>

    <form wire:submit="save" class="space-y-8">
        <section class="space-y-4" data-test="settings-registration">
            <flux:heading>{{ __('Registration') }}</flux:heading>
            <flux:select wire:model="registrationMode" :label="__('Who can sign up')" data-test="setting-registration-mode">
                <flux:select.option value="">{{ __('Environment default (:value)', ['value' => str_replace('_', ' ', $defaults['registration_mode'])]) }}</flux:select.option>
                @foreach ($registrationModes as $mode)
                    <flux:select.option value="{{ $mode->value }}">{{ __(ucfirst(str_replace('_', ' ', $mode->value))) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:text size="sm">{{ __('The sign-up pages themselves only exist while the environment allows registration; a restart is needed to reopen them.') }}</flux:text>
        </section>

        <section class="space-y-4" data-test="settings-keys">
            <flux:heading>{{ __('Provider API keys') }}</flux:heading>
            @foreach ([
                ['field' => 'openaiKey', 'provider' => 'openai', 'label' => __('OpenAI key')],
                ['field' => 'anthropicKey', 'provider' => 'anthropic', 'label' => __('Anthropic key')],
                ['field' => 'contextDevKey', 'provider' => 'context_dev', 'label' => __('Context.dev key')],
            ] as $row)
                <div class="space-y-1" wire:key="key-{{ $row['provider'] }}">
                    <flux:input wire:model="{{ $row['field'] }}" type="password" autocomplete="new-password" :label="$row['label']" :placeholder="$hints[$row['provider']] && $hints[$row['provider']]['hint'] !== '' ? __('Saved :hint, type to replace', ['hint' => $hints[$row['provider']]['hint']]) : __('Not set')" data-test="setting-{{ $row['provider'] }}-key" />
                    @if ($hints[$row['provider']] !== null)
                        <div class="flex items-center gap-3 text-sm text-zinc-500" data-test="key-hint-{{ $row['provider'] }}">
                            <span>{{ match ($hints[$row['provider']]['source']) {
                                'saved' => __('Saved key ending :hint', ['hint' => $hints[$row['provider']]['hint']]),
                                'unreadable' => __('A saved key cannot be read (the app key changed). Remove it or type a new one.'),
                                default => __('From the environment, ending :hint', ['hint' => $hints[$row['provider']]['hint']]),
                            } }}</span>
                            @if (in_array($hints[$row['provider']]['source'], ['saved', 'unreadable'], true))
                                <flux:button size="xs" variant="ghost" type="button" wire:click="clearKey('{{ $row['provider'] }}')" wire:confirm="{{ __('Remove this saved key?') }}" data-test="clear-{{ $row['provider'] }}-key">{{ __('Remove') }}</flux:button>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </section>

        <section class="space-y-4" data-test="settings-llm">
            <flux:heading>{{ __('AI assistant') }}</flux:heading>
            <flux:select wire:model="assistantMode" :label="__('Assistant mode')" data-test="setting-assistant-mode">
                <flux:select.option value="">{{ __('Environment default (:value)', ['value' => str_replace('_', ' ', $defaults['assistant_mode'])]) }}</flux:select.option>
                @foreach ($assistantModes as $mode)
                    <flux:select.option value="{{ $mode->value }}">{{ __($mode->label()) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="llmProvider" :label="__('Provider')" data-test="setting-llm-provider">
                <flux:select.option value="">{{ __('Environment default (:value)', ['value' => $defaults['llm_provider']]) }}</flux:select.option>
                <flux:select.option value="openai">OpenAI</flux:select.option>
                <flux:select.option value="anthropic">Anthropic</flux:select.option>
            </flux:select>
            <flux:input wire:model="llmModel" :label="__('Model')" :placeholder="$defaults['llm_model']" data-test="setting-llm-model" />
            <flux:input wire:model="llmEndpoint" type="url" :label="__('Endpoint (optional)')" :placeholder="$defaults['llm_endpoint'] !== '' ? $defaults['llm_endpoint'] : __('Provider default')" data-test="setting-llm-endpoint" />
            <flux:input wire:model="llmTokenBudget" type="number" min="64" max="200000" :label="__('Token budget per reply')" :placeholder="$defaults['llm_token_budget']" data-test="setting-llm-token-budget" />
            <flux:textarea wire:model="systemPrompt" rows="4" :label="__('System prompt override (optional)')" :description="__('Replaces the built-in instructions; the user\'s accounts, categories and date are still added.')" data-test="setting-system-prompt" />
        </section>

        <section class="space-y-4" data-test="settings-sync">
            <flux:heading>{{ __('Bank feeds') }}</flux:heading>
            <flux:select wire:model="includePending" :label="__('Include pending transactions')" data-test="setting-include-pending">
                <flux:select.option value="">{{ __('Environment default (:value)', ['value' => $defaults['include_pending'] === '1' ? __('yes') : __('no')]) }}</flux:select.option>
                <flux:select.option value="1">{{ __('Yes') }}</flux:select.option>
                <flux:select.option value="0">{{ __('No') }}</flux:select.option>
            </flux:select>
            <flux:select wire:model="autoSync" :label="__('Auto-sync')" data-test="setting-auto-sync">
                <flux:select.option value="default">{{ __('Default (every six hours)') }}</flux:select.option>
                <flux:select.option value="on">{{ __('Once a day') }}</flux:select.option>
                <flux:select.option value="off">{{ __('Off') }}</flux:select.option>
            </flux:select>
            <flux:input wire:model="autoSyncTime" type="time" :label="__('Daily sync time')" data-test="setting-auto-sync-time" />
            @error('autoSyncTime')
                <flux:callout variant="danger" icon="exclamation-triangle" data-test="sync-time-alert"><flux:callout.text>{{ $message }}</flux:callout.text></flux:callout>
            @enderror
        </section>

        <flux:button type="submit" variant="primary" data-test="save-settings-button">{{ __('Save settings') }}</flux:button>
    </form>
</div>
