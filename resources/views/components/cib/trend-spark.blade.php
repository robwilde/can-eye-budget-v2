@props([
    'points',
    'favourable' => true,
])

@php
    $values = array_values($points);
    $count = count($values);
    $width = 80;
    $height = 24;
    $min = $values === [] ? 0 : min($values);
    $range = $values === [] ? 1 : (max($values) - $min ?: 1);
    $coordinates = [];

    foreach ($values as $index => $value) {
        $x = $count > 1 ? round($index / ($count - 1) * $width, 1) : 0;
        $y = round($height - 2 - (($value - $min) / $range) * ($height - 4), 1);
        $coordinates[] = $x.','.$y;
    }
@endphp

@if ($count > 1)
    <svg {{ $attributes->class(['shrink-0']) }} width="{{ $width }}" height="{{ $height }}" viewBox="0 0 {{ $width }} {{ $height }}"
         role="img" aria-label="{{ __('Balance trend') }}" data-trend="{{ $favourable ? 'favourable' : 'unfavourable' }}">
        <polyline fill="none" stroke="{{ $favourable ? '#18923A' : '#E5484D' }}" stroke-width="2"
                  stroke-linecap="round" stroke-linejoin="round" points="{{ implode(' ', $coordinates) }}"/>
    </svg>
@endif
