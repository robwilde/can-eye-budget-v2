@use('App\Casts\MoneyCast')

@props([
    'name',
    'spent',
    'limit',
])

@php
    $spentCents = (int) $spent;
    $limitCents = (int) $limit;
    $over = $spentCents > $limitCents;
    $pct = $limitCents > 0 ? min(100, ($spentCents / $limitCents) * 100) : 0;
@endphp

<div {{ $attributes }}>
    <div class="budget-row">
        <span>{{ $name }}</span>
        <span class="amt"><b>{{ MoneyCast::format($spentCents) }}</b> / {{ MoneyCast::format($limitCents) }}</span>
    </div>
    <div class="track">
        <div @class(['fill', 'over' => $over]) style="width: {{ $pct }}%"></div>
    </div>
</div>
