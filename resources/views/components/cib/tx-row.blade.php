@use('App\Casts\MoneyCast')
@use('Illuminate\Support\Js')

@props([
    'transactionId' => null,
    'plannedTransactionId' => null,
    'occurrenceDate' => null,
    'name',
    'amount',
    'tone' => 'out',
    'icon' => null,
])

@php
    $isPlanned = $plannedTransactionId !== null;
    $isPassive = $transactionId === null && $plannedTransactionId === null;

    if (! $isPassive && isset($actions)) {
        throw new InvalidArgumentException(
            '<x-cib.tx-row> actions slot requires passive mode — both transactionId and plannedTransactionId must be null when an actions slot is supplied.'
        );
    }
@endphp

@if ($isPassive)
    <div {{ $attributes->class(['tx-row', 'passive']) }}>
        <div @class(['tx-ico', $tone])>
            @if ($icon)
                <flux:icon :name="$icon" variant="mini"/>
            @endif
        </div>
        <div>
            <div class="tx-name">{{ $name }}</div>
            @isset($meta)
                <div class="tx-meta">{{ $meta }}</div>
            @endisset
        </div>
        <div @class(['tx-amt', $tone])>{{ MoneyCast::format((int) $amount) }}</div>
        @isset($actions)
            <div class="tx-actions">{{ $actions }}</div>
        @endisset
    </div>
@else
    <button type="button"
            {{ $attributes->class(['tx-row', 'planned' => $isPlanned]) }}
            @if ($isPlanned)
                wire:click="$dispatch('open-reconciliation-modal', { plannedId: {{ (int) $plannedTransactionId }}, occurrenceDate: {{ Js::from((string) $occurrenceDate) }} })"
            @else
                wire:click="$dispatch('edit-transaction', { id: {{ (int) $transactionId }} })"
            @endif
    >
        <div @class(['tx-ico', $tone])>
            @if ($icon)
                <flux:icon :name="$icon" variant="mini"/>
            @endif
        </div>
        <div>
            <div class="tx-name">{{ $name }}</div>
            @isset($meta)
                <div class="tx-meta">{{ $meta }}</div>
            @endisset
        </div>
        <div @class(['tx-amt', $tone])>{{ MoneyCast::format((int) $amount) }}</div>
    </button>
@endif
