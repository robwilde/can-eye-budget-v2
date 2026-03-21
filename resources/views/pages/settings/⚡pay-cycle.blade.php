<?php

use App\Enums\PayFrequency;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pay cycle settings')] class extends Component {
    public string $pay_amount = '';
    public string $pay_frequency = '';
    public string $next_pay_date = '';
    public function mount(): void
    {
        $user = Auth::user();

        $this->pay_amount = $user->pay_amount ? number_format($user->pay_amount / 100, 2, '.', '') : '';
        $this->pay_frequency = $user->pay_frequency?->value ?? '';
        $this->next_pay_date = $user->next_pay_date?->format('Y-m-d') ?? '';
    }

    public function save(): void
    {
        $validated = $this->validate([
            'pay_amount' => ['required', 'numeric', 'min:0'],
            'pay_frequency' => ['required', Rule::enum(PayFrequency::class)],
            'next_pay_date' => ['required', 'date', 'after_or_equal:today'],
        ]);

        Auth::user()->update([
            'pay_amount' => (int) round((float) $validated['pay_amount'] * 100),
            'pay_frequency' => $validated['pay_frequency'],
            'next_pay_date' => $validated['next_pay_date'],
        ]);

        $this->dispatch('pay-cycle-updated');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Pay cycle settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Pay cycle')" :subheading="__('Configure your pay schedule')">
        <form wire:submit="save" class="my-6 w-full space-y-6">
            <flux:input
                wire:model="pay_amount"
                :label="__('Pay amount per cycle')"
                type="number"
                step="0.01"
                min="0"
                placeholder="0.00"
                :description="__('How much you receive each pay cycle (before tax)')"
                required
            />

            <flux:select wire:model="pay_frequency" :label="__('Pay frequency')" required>
                <flux:select.option value="">{{ __('Select frequency') }}</flux:select.option>
                @foreach(PayFrequency::cases() as $frequency)
                    <flux:select.option value="{{ $frequency->value }}">{{ $frequency->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                wire:model="next_pay_date"
                :label="__('Next pay date')"
                type="date"
                required
            />

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="save-pay-cycle-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>

                <x-action-message class="me-3" on="pay-cycle-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
