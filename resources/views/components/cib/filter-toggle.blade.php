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
                    @if ($option['value'] === null)
                        wire:click="$set('{{ $wireModel }}', null)"
                    @else
                        wire:click="$set('{{ $wireModel }}', '{{ $option['value'] }}')"
                    @endif
                @endif
        >{{ $option['label'] }}</button>
    @endforeach
</div>
