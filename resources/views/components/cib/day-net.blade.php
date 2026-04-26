@use('App\Casts\MoneyCast')

@props([
    'cents',
])

@php
    $value = (int) $cents;
    $tone = match (true) {
        $value > 0 => 'pos',
        $value < 0 => 'neg',
        default => '',
    };
    $sign = match (true) {
        $value > 0 => '+',
        $value < 0 => '−',
        default => '',
    };
    $abs = MoneyCast::format(abs($value));
@endphp

@if ($value !== 0)
    <span {{ $attributes->class(['cyc-day-net', $tone]) }}>{{ $sign }}{{ $abs }}</span>
@endif
