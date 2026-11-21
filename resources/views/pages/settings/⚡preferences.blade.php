<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Preferences')] class extends Component {
    public bool $rulePromptEnabled = true;

    #[Locked]
    public bool $storedRulePromptEnabled = true;

    #[Locked]
    public ?string $pausedUntil = null;

    public function mount(): void
    {
        $user = Auth::user();
        $preferences = $user->preferences;
        $until = $preferences->rulePromptHiddenUntil;

        $this->rulePromptEnabled = $this->storedRulePromptEnabled = $preferences->rulePromptEnabled;
        $this->pausedUntil = $until?->greaterThan(CarbonImmutable::now()) === true
            ? $until->timezone($user->resolvedTimezone())->format('j M Y, g:ia')
            : null;
    }

    /**
     * An untouched save changes nothing. Switching on clears a pause; switching off is permanent.
     */
    public function save(): void
    {
        $this->validate(['rulePromptEnabled' => ['boolean']]);

        if ($this->rulePromptEnabled !== $this->storedRulePromptEnabled) {
            Auth::user()->updatePreferences($this->rulePromptEnabled
                ? ['rulePromptEnabled' => true, 'rulePromptHiddenUntil' => null]
                : ['rulePromptEnabled' => false]);

            $this->storedRulePromptEnabled = $this->rulePromptEnabled;

            if ($this->rulePromptEnabled) {
                $this->pausedUntil = null;
            }
        }

        $this->dispatch('preferences-updated');
    }

    public function resumeRuleSuggestions(): void
    {
        Auth::user()->updatePreferences(['rulePromptHiddenUntil' => null]);

        $this->pausedUntil = null;
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Preferences') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Preferences')" :subheading="__('How the app behaves for you')">
        <form wire:submit="save" class="my-6 w-full space-y-6">
            <flux:switch
                wire:model="rulePromptEnabled"
                :label="__('Suggest rules when categorizing')"
                :description="__('After you change a transaction\'s category, offer to create a rule for it.')"
                data-test="rule-prompt-enabled"
            />

            @if($pausedUntil !== null)
                <div class="flex flex-wrap items-center gap-3" data-test="rule-prompt-paused">
                    <flux:text size="sm">{{ __('Suggestions are paused until :time.', ['time' => $pausedUntil]) }}</flux:text>
                    <flux:button size="sm" variant="ghost" wire:click="resumeRuleSuggestions" data-test="rule-prompt-resume">{{ __('Resume now') }}</flux:button>
                </div>
            @endif

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit" data-test="save-preferences-button">
                    {{ __('Save') }}
                </flux:button>

                <x-action-message class="me-3" on="preferences-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
