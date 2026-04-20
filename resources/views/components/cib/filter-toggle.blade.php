@props([
    'options' => [],
    'selected' => null,
    'wireModel' => null,
])

<div {{ $attributes->class(['type-toggle']) }}>
    @foreach ($options as $option)
        @php
            $isActive = $option['value'] === $selected;
            $tone = $option['tone'] ?? null;
        @endphp
        <button type="button"
                @class([
                    'active' => $isActive,
                    $tone => $isActive && $tone,
                ])
                @if ($wireModel)
                    wire:click="$set('{{ $wireModel }}', '{{ $option['value'] }}')"
                @endif
        >{{ $option['label'] }}</button>
    @endforeach
</div>
