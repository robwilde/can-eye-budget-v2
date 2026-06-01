@use('App\Casts\MoneyCast')

@props([
    'debit' => 0,
    'credit' => 0,
])

@php
    $debitCents = (int) $debit;
    $creditCents = (int) $credit;
@endphp

@if ($debitCents !== 0 || $creditCents !== 0)
    <span {{ $attributes->class('cyc-day-net') }}>
        @if ($debitCents !== 0)
            <span class="cyc-day-debit">−{{ MoneyCast::format(abs($debitCents)) }}</span>
        @endif
        @if ($creditCents !== 0)
            <span class="cyc-day-credit">+{{ MoneyCast::format(abs($creditCents)) }}</span>
        @endif
    </span>
@endif
