@use('App\Casts\MoneyCast')

@props([
    'label',
    'amount',
    'tone' => 'needed',
    'hint' => null,
])

<div {{ $attributes->class(['mcard', $tone]) }}>
    <div class="mhead">
        <div class="mlabel">{{ $label }}</div>
        @isset($badge)
            <div class="mbadge">{{ $badge }}</div>
        @endisset
    </div>
    <div class="mmoney">{{ MoneyCast::format((int) $amount) }}</div>
    @if ($hint)
        <div class="mhint">{{ $hint }}</div>
    @endif
</div>
