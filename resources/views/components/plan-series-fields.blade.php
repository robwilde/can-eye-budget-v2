@props(['typeChoices', 'amountStrategies', 'hasLink' => false])

<div class="space-y-4" data-testid="plan-series-fields">
    <flux:field>
        <flux:label>{{ __('Type') }}</flux:label>
        <flux:select wire:model.live="planType" :disabled="$typeChoices['locked']" data-testid="plan-type-select">
            @foreach($typeChoices['options'] as $typeOption)
                <flux:select.option value="{{ $typeOption->value }}">{{ $typeOption->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        @if($typeChoices['locked'])
            <flux:description>{{ __('A plan with a destination account is always a transfer.') }}</flux:description>
        @endif
        <flux:error name="planType" />
    </flux:field>

    <flux:field>
        <flux:label>{{ __('Payment link') }}</flux:label>
        <flux:input wire:model.live.blur="paymentUrl" type="text" inputmode="url" maxlength="2048" autocomplete="off" placeholder="example.com/pay" data-testid="plan-payment-url" />
        <flux:error name="paymentUrl" />
    </flux:field>

    @if($hasLink)
        <flux:checkbox wire:model="copyPaymentUrl" :label="__('Copy this link to my other bills')" :description="__('Plans with the same merchant or the exact same name.')" data-testid="plan-copy-payment-url" />
    @endif

    <flux:checkbox wire:model="autopay" :label="__('Autopay')" :description="__('The biller takes this payment automatically.')" data-testid="plan-autopay" />

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <flux:field>
            <flux:label>{{ __('Renews on') }}</flux:label>
            <flux:input wire:model="renewalOn" type="date" />
            <flux:error name="renewalOn" />
        </flux:field>
        <flux:field>
            <flux:label>{{ __('Trial ends') }}</flux:label>
            <flux:input wire:model="trialEndsOn" type="date" />
            <flux:error name="trialEndsOn" />
        </flux:field>
        <flux:field>
            <flux:label>{{ __('Cancelled on') }}</flux:label>
            <flux:input wire:model="cancelledOn" type="date" />
            <flux:error name="cancelledOn" />
        </flux:field>
    </div>

    <details wire:ignore.self class="rounded-lg border border-neutral-200 px-3 py-2" data-testid="plan-tuning">
        <summary class="cursor-pointer text-sm font-medium">{{ __('Reminders and matching') }}</summary>

        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <flux:field>
                <flux:label>{{ __('Remind me (days before)') }}</flux:label>
                <flux:input wire:model="reminderDays" type="number" min="0" max="365" step="1" />
                <flux:error name="reminderDays" />
            </flux:field>
            <flux:field>
                <flux:label>{{ __('Overdue grace (days)') }}</flux:label>
                <flux:input wire:model="graceDays" type="number" min="0" max="365" step="1" />
                <flux:error name="graceDays" />
            </flux:field>
            <flux:field>
                <flux:label>{{ __('Amount tolerance (%)') }}</flux:label>
                <flux:input wire:model="tolerancePercent" type="number" min="0" max="100" step="0.01" />
                <flux:error name="tolerancePercent" />
            </flux:field>
            <flux:field>
                <flux:label>{{ __('Amount strategy') }}</flux:label>
                <flux:select wire:model="amountStrategy">
                    @foreach($amountStrategies as $strategy)
                        <flux:select.option value="{{ $strategy->value }}">{{ $strategy->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="amountStrategy" />
            </flux:field>
            <flux:field>
                <flux:label>{{ __('Match early (days)') }}</flux:label>
                <flux:input wire:model="matchEarlyDays" type="number" min="0" max="90" step="1" />
                <flux:error name="matchEarlyDays" />
            </flux:field>
            <flux:field>
                <flux:label>{{ __('Match late (days)') }}</flux:label>
                <flux:input wire:model="matchLateDays" type="number" min="0" max="90" step="1" />
                <flux:error name="matchLateDays" />
            </flux:field>
        </div>
    </details>
</div>
