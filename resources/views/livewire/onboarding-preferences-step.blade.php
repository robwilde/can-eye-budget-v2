<div class="space-y-4" data-test="onboarding-preferences-step">
    <div class="space-y-2">
        <flux:heading size="xl">{{ __('Set your preferences') }}</flux:heading>
        <flux:text>{{ __('Pick how the app looks and which currency and timezone it uses. You can change all of this later under Settings > Preferences.') }}</flux:text>
    </div>

    <form wire:submit="save" class="space-y-6">
        <flux:select wire:model="theme" :label="__('Theme')" data-test="onboarding-theme">
            @foreach(App\Enums\Theme::cases() as $option)
                <flux:select.option value="{{ $option->value }}">{{ __($option->label()) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model="locale" :label="__('Language')" data-test="onboarding-locale">
            @foreach(App\Enums\SupportedLocale::cases() as $option)
                <flux:select.option value="{{ $option->value }}">{{ $option->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model="baseCurrency" :label="__('Base currency')" data-test="onboarding-base-currency">
            @foreach(App\Support\Currencies::NAMES as $code => $name)
                <flux:select.option value="{{ $code }}">{{ $code }} — {{ $name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model="timezone" :label="__('Timezone')" data-test="onboarding-timezone">
            <flux:select.option value="">{{ __('App default (:zone)', ['zone' => config('app.timezone')]) }}</flux:select.option>
            @foreach(DateTimeZone::listIdentifiers() as $identifier)
                <flux:select.option value="{{ $identifier }}">{{ $identifier }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model="monthStartDay" :label="__('Month start day')" data-test="onboarding-month-start-day">
            <flux:select.option value="">{{ __('Calendar month') }}</flux:select.option>
            @foreach(range(1, 28) as $day)
                <flux:select.option value="{{ $day }}">{{ $day }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="flex items-center gap-4">
            <flux:button type="submit" variant="primary" data-test="onboarding-preferences-save">{{ __('Save and continue') }}</flux:button>
            <flux:button type="button" wire:click="skip" variant="ghost" data-test="onboarding-preferences-skip">{{ __('Skip for now') }}</flux:button>
        </div>
    </form>
</div>
