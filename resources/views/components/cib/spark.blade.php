@props([
    'values',
    'paydayIndexes' => [],
])

@php
    $values = array_values($values);
    $max = 1;
    $tallest = null;

    if ($values !== []) {
        $max = max($values) ?: 1;
        $tallest = array_search($max, $values, true);
    }
@endphp

<div {{ $attributes->class(['spark']) }}>
    @foreach ($values as $i => $v)
        @php
            $h = max(3, (int) round(($v / $max) * 100));
            $big = $i === $tallest || in_array($i, $paydayIndexes, true);
        @endphp
        <div @class(['bar', 'big' => $big]) style="height: {{ $h }}%"></div>
    @endforeach
</div>
