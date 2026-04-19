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
@endphp

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
