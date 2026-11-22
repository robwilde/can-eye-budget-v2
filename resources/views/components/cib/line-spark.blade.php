@props(['points', 'favourable' => true])

@php
    $values = array_values($points);
    $low = $values === [] ? 0 : min($values);
    $span = $values === [] ? 1 : max(1, max($values) - $low);
    $step = count($values) > 1 ? 120 / (count($values) - 1) : 0;
    $line = collect($values)->map(fn (int $value, int $index): string => round($index * $step, 1).','.round(28 - ($value - $low) / $span * 26, 1))->implode(' ');
@endphp

<svg {{ $attributes->class(['h-8 w-28']) }} viewBox="0 0 120 30" preserveAspectRatio="none" role="img" aria-hidden="true">
    <polyline fill="none" stroke="{{ $favourable ? '#18923A' : '#E5484D' }}" stroke-width="2" stroke-linecap="round" vector-effect="non-scaling-stroke" points="{{ count($values) === 1 ? $line.' '.$line : $line }}"/>
</svg>
