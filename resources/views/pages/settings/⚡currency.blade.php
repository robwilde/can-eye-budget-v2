<?php

use App\Models\User;
use App\Support\Currencies;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Currency settings')] class extends Component {
    public string $base_currency = '';

    public array $allowed = [];

    public function mount(): void
    {
        $user = Auth::user();

        $this->base_currency = $user->base_currency;
        $this->allowed = $user->preferences->allowedCurrencies;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'base_currency' => ['required', 'string', Rule::in(Currencies::codes())],
            'allowed' => ['array'],
            'allowed.*' => ['string', Rule::in(Currencies::codes())],
        ]);

        $user = Auth::user();
        $base = $validated['base_currency'];
        $allowed = Currencies::normaliseList($validated['allowed']);
        $allowed = $allowed === [] ? [] : array_values(array_unique([$base, ...$allowed]));

        User::query()->getConnection()->transaction(function () use ($user, $base, $allowed): void {
            $user->update(['base_currency' => $base]);
            $user->updatePreferences(['allowedCurrencies' => $allowed]);
        });

        $this->allowed = $allowed;
        $this->dispatch('currency-updated');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Currency settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Currency')" :subheading="__('The default currency for new accounts and transactions')">
        <form wire:submit="save" class="my-6 w-full space-y-6">
            <flux:select wire:model="base_currency" :label="__('Base currency')" data-test="base-currency" required>
                @foreach(Currencies::NAMES as $code => $name)
                    <flux:select.option value="{{ $code }}">{{ $code }} — {{ $name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:checkbox.group
                wire:model="allowed"
                :label="__('Allowed currencies')"
                :description="__('The currencies offered when you add an account or a transaction. Leave all unticked to offer every currency. Your base currency is always included.')"
            >
                <div class="grid grid-cols-2 gap-2">
                    @foreach(Currencies::NAMES as $code => $name)
                        <flux:checkbox value="{{ $code }}" label="{{ $code }} — {{ $name }}"/>
                    @endforeach
                </div>
            </flux:checkbox.group>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="save-currency-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>

                <x-action-message class="me-3" on="currency-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
