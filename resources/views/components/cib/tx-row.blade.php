@use('App\Casts\MoneyCast')

@props([
    'transactionId',
    'name',
    'amount',
    'tone' => 'out',
])

<button type="button"
        {{ $attributes->class(['tx-row']) }}
        wire:click="$dispatch('edit-transaction', { id: {{ (int) $transactionId }} })">
    <div @class(['tx-ico', $tone])>{{ $icon ?? '' }}</div>
    <div>
        <div class="tx-name">{{ $name }}</div>
        @isset($meta)
            <div class="tx-meta">{{ $meta }}</div>
        @endisset
    </div>
    <div @class(['tx-amt', $tone])>{{ MoneyCast::format((int) $amount) }}</div>
</button>
