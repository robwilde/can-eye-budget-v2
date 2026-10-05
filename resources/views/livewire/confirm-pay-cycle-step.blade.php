<div class="space-y-6" @if ($this->isAnalysing) wire:poll.3s @endif data-test="confirm-pay-cycle-step">
    <div>
        <flux:heading size="lg">{{ __('Confirm your primary account and pay cycle') }}</flux:heading>
        <flux:subheading>
            {{ __('Your budget is organised around the account your pay lands in and how often you are paid.') }}
        </flux:subheading>
    </div>

    @if ($this->isAnalysing)
        <flux:callout icon="arrow-path" data-test="pay-cycle-analysing">
            <flux:callout.heading>{{ __('Looking through your transactions…') }}</flux:callout.heading>
            <flux:callout.text>{{ __('We are looking for your pay. This page will update automatically.') }}</flux:callout.text>
        </flux:callout>
    @else
        @if ($prefilled && $payFrequency !== '')
            <flux:callout icon="sparkles" data-test="pay-cycle-detected">
                <flux:callout.text>{{ __('We filled these in from your transactions. Change anything that is not right.') }}</flux:callout.text>
            </flux:callout>
        @else
            <flux:callout icon="information-circle" data-test="pay-cycle-manual">
                <flux:callout.text>{{ __('We could not detect your pay from your transactions. Enter it below.') }}</flux:callout.text>
            </flux:callout>
        @endif

        <form wire:submit="confirm" class="space-y-4">
            <flux:select wire:model="primaryAccountId" :label="__('Primary account')" :placeholder="__('Choose an account')" data-test="pay-cycle-account">
                @foreach ($this->accounts as $account)
                    <flux:select.option value="{{ $account->id }}">{{ $account->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="payAmount" type="number" step="0.01" min="0" :label="__('Pay amount')" data-test="pay-cycle-amount" />

            <flux:select wire:model="payFrequency" :label="__('How often are you paid?')" :placeholder="__('Choose how often')" data-test="pay-cycle-frequency">
                @foreach ($frequencies as $frequency)
                    <flux:select.option value="{{ $frequency->value }}">{{ $frequency->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="nextPayDate" type="date" :label="__('Next pay date')" data-test="pay-cycle-next-date" />

            <flux:button type="submit" variant="primary" data-test="pay-cycle-confirm">
                {{ __('Confirm') }}
            </flux:button>
        </form>
    @endif
</div>
