<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\SupportedLocale;
use App\Enums\Theme;
use App\Models\User;
use App\Support\Currencies;
use DateTimeZone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Onboarding step after the pay cycle: theme, language, base currency, timezone and month start,
 * written through the same preference store and user columns as the Preferences page.
 */
final class OnboardingPreferencesStep extends Component
{
    public string $theme = 'system';

    public string $locale = 'en';

    public string $baseCurrency = 'AUD';

    public string $timezone = '';

    public ?int $monthStartDay = null;

    public function mount(): void
    {
        $user = $this->user();

        $this->theme = $user->preferences->theme->value;
        $this->locale = ($user->preferences->locale ?? SupportedLocale::tryFrom(mb_substr(app()->getLocale(), 0, 2)) ?? SupportedLocale::English)->value;
        $this->baseCurrency = $user->base_currency;
        $this->timezone = $user->timezone ?? '';
        $this->monthStartDay = $user->month_start_day;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'theme' => ['required', Rule::enum(Theme::class)],
            'locale' => ['required', Rule::enum(SupportedLocale::class)],
            'baseCurrency' => ['required', 'string', Rule::in(Currencies::codes())],
            'timezone' => ['nullable', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'monthStartDay' => ['nullable', 'integer', 'between:1,28'],
        ]);

        $user = $this->user();
        $allowed = $user->preferences->allowedCurrencies;
        $preferences = ['theme' => $validated['theme'], 'locale' => $validated['locale']];

        if ($allowed !== [] && ! in_array($validated['baseCurrency'], $allowed, true)) {
            $preferences['allowedCurrencies'] = [$validated['baseCurrency'], ...$allowed];
        }

        $user->getConnection()->transaction(function () use ($user, $validated, $preferences): void {
            $user->update([
                'base_currency' => $validated['baseCurrency'],
                'timezone' => $validated['timezone'] === '' ? null : $validated['timezone'],
                'month_start_day' => $validated['monthStartDay'],
            ]);
            $user->updatePreferences($preferences);
        });

        $this->dispatch('theme-changed', theme: $validated['theme']);
        $this->dispatch('onboarding-preferences-done');
    }

    public function skip(): void
    {
        $this->dispatch('onboarding-preferences-done');
    }

    public function render(): View
    {
        return view('livewire.onboarding-preferences-step');
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
