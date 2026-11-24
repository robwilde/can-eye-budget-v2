@props(['frequency', 'scope' => 'cadence'])

@php
    $selected = \App\Enums\RecurrenceFrequency::tryFrom($frequency);
@endphp

<div class="space-y-3" data-testid="{{ $scope }}-cadence-fields">
    @if($selected?->usesInterval())
        <flux:field>
            <flux:label>{{ match($selected) { \App\Enums\RecurrenceFrequency::EveryNWeeks => __('Every how many weeks'), \App\Enums\RecurrenceFrequency::EveryNMonths => __('Every how many months'), default => __('Every how many years') } }}</flux:label>
            <flux:input wire:model="recurrenceInterval" type="number" min="1" max="{{ $selected->maxInterval() }}" step="1" data-testid="{{ $scope }}-cadence-interval" />
            <flux:error name="recurrenceInterval" />
        </flux:field>
    @endif

    @if($selected?->supportsDaysOfMonth())
        <flux:field>
            <flux:label>{{ __('Days of the month') }}</flux:label>
            <flux:input wire:model="daysOfMonth" type="text" maxlength="120" placeholder="{{ __('Start date\'s day, or e.g. 1, 15, last') }}" data-testid="{{ $scope }}-cadence-days" />
            <flux:description>{{ __('Use "last" for the last day of every month.') }}</flux:description>
            <flux:error name="daysOfMonth" />
        </flux:field>
    @endif

    @if($selected === null || $selected->isPickable())
    <flux:field>
        <flux:label>{{ __('When a date falls on a weekend') }}</flux:label>
        <flux:select wire:model="weekendAdjustment" data-testid="{{ $scope }}-cadence-weekend">
            @foreach(\App\Enums\WeekendAdjustment::cases() as $adjustment)
                <flux:select.option value="{{ $adjustment->value }}">{{ $adjustment->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:error name="weekendAdjustment" />
    </flux:field>
    @endif
</div>
