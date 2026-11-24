<?php

use App\Enums\SupportedLocale;
use App\Enums\Theme;
use App\Enums\TransactionPeriod;
use App\Models\User;
use App\Support\Currencies;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Preferences')] class extends Component {
    public string $theme = 'system';

    public string $locale = 'en';

    public string $baseCurrency = 'AUD';

    public string $timezone = '';

    public ?int $monthStartDay = null;

    public string $defaultPeriod = 'this-month';

    public bool $previewFeatures = false;

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

        $this->theme = $preferences->theme->value;
        $this->locale = ($preferences->locale ?? SupportedLocale::tryFrom(mb_substr(app()->getLocale(), 0, 2)) ?? SupportedLocale::English)->value;
        $this->baseCurrency = $user->base_currency;
        $this->timezone = $user->timezone ?? '';
        $this->monthStartDay = $user->month_start_day;
        $this->defaultPeriod = $user->defaultListPeriod();
        $this->previewFeatures = $preferences->previewFeatures;
        $this->rulePromptEnabled = $this->storedRulePromptEnabled = $preferences->rulePromptEnabled;
        $this->pausedUntil = $until?->greaterThan(CarbonImmutable::now()) === true
            ? $until->timezone($user->resolvedTimezone())->format('j M Y, g:ia')
            : null;
    }

    /**
     * @return list<TransactionPeriod>
     */
    #[Computed]
    public function periodOptions(): array
    {
        $user = Auth::user();

        return array_values(array_filter(
            TransactionPeriod::defaultOptions(),
            static fn (TransactionPeriod $period): bool => $period !== TransactionPeriod::PayCycle || $user->hasPayCycleConfigured(),
        ));
    }

    /**
     * Validates everything before assigning anything, so one bad field saves nothing. An untouched
     * rule-suggestion switch changes nothing; switching it on clears a pause, switching it off is permanent.
     */
    public function save(): void
    {
        $validated = $this->validate([
            'theme' => ['required', Rule::enum(Theme::class)],
            'locale' => ['required', Rule::enum(SupportedLocale::class)],
            'baseCurrency' => ['required', 'string', Rule::in(Currencies::codes())],
            'timezone' => ['nullable', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'monthStartDay' => ['nullable', 'integer', 'between:1,28'],
            'defaultPeriod' => ['required', Rule::in(array_map(static fn (TransactionPeriod $period): string => $period->value, $this->periodOptions))],
            'previewFeatures' => ['boolean'],
            'rulePromptEnabled' => ['boolean'],
        ]);

        $user = Auth::user();
        $allowed = $user->preferences->allowedCurrencies;
        $preferences = [
            'theme' => $validated['theme'],
            'locale' => $validated['locale'],
            'defaultPeriod' => $validated['defaultPeriod'],
            'previewFeatures' => $validated['previewFeatures'],
        ];

        if ($allowed !== [] && ! in_array($validated['baseCurrency'], $allowed, true)) {
            $preferences['allowedCurrencies'] = [$validated['baseCurrency'], ...$allowed];
        }

        if ($validated['rulePromptEnabled'] !== $this->storedRulePromptEnabled) {
            $preferences += $validated['rulePromptEnabled']
                ? ['rulePromptEnabled' => true, 'rulePromptHiddenUntil' => null]
                : ['rulePromptEnabled' => false];
        }

        User::query()->getConnection()->transaction(function () use ($user, $validated, $preferences): void {
            $user->update([
                'base_currency' => $validated['baseCurrency'],
                'timezone' => $validated['timezone'] === '' ? null : $validated['timezone'],
                'month_start_day' => $validated['monthStartDay'],
            ]);
            $user->updatePreferences($preferences);
        });

        if ($validated['rulePromptEnabled'] && ! $this->storedRulePromptEnabled) {
            $this->pausedUntil = null;
        }

        $this->storedRulePromptEnabled = $validated['rulePromptEnabled'];

        $this->dispatch('theme-changed', theme: $validated['theme']);
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
            <flux:select wire:model="theme" :label="__('Theme')" data-test="preferences-theme">
                @foreach(App\Enums\Theme::cases() as $option)
                    <flux:select.option value="{{ $option->value }}">{{ __($option->label()) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="locale" :label="__('Language')" data-test="preferences-locale">
                @foreach(App\Enums\SupportedLocale::cases() as $option)
                    <flux:select.option value="{{ $option->value }}">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="baseCurrency" :label="__('Base currency')" :description="__('Only changes the currency reports are shown in. Stored amounts keep their own currency.')" data-test="preferences-base-currency">
                @foreach(App\Support\Currencies::NAMES as $code => $name)
                    <flux:select.option value="{{ $code }}">{{ $code }} — {{ $name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="timezone" :label="__('Timezone')" data-test="preferences-timezone">
                <flux:select.option value="">{{ __('App default (:zone)', ['zone' => config('app.timezone')]) }}</flux:select.option>
                @foreach(DateTimeZone::listIdentifiers() as $identifier)
                    <flux:select.option value="{{ $identifier }}">{{ $identifier }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="monthStartDay" :label="__('Month start day')" :description="__('The day your budget month starts. Leave on calendar month to start on the 1st.')" data-test="preferences-month-start-day">
                <flux:select.option value="">{{ __('Calendar month') }}</flux:select.option>
                @foreach(range(1, 28) as $day)
                    <flux:select.option value="{{ $day }}">{{ $day }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="defaultPeriod" :label="__('Default period')" :description="__('The period the transactions list and reports open on.')" data-test="preferences-default-period">
                @foreach($this->periodOptions as $option)
                    <flux:select.option value="{{ $option->value }}">{{ __($option->label()) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:switch
                wire:model="previewFeatures"
                :label="__('Preview features')"
                :description="__('Try features that are still being built. They may change or be removed.')"
                data-test="preview-features-enabled"
            />

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
