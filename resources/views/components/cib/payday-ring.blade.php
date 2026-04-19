@props(['days'])

<span {{ $attributes->class(['payday-ring']) }}>
    <span class="pr">{{ (int) $days }}</span>
    @if ((int) $days === 0)
        <span>It's payday</span>
    @else
        <span>days to payday</span>
    @endif
</span>
