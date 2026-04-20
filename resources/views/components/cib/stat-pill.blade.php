@props([
    'tone' => 'neutral',
    'label' => null,
    'value' => null,
])

<span {{ $attributes->class([
    'stat-pill',
    'stat-pill-income' => $tone === 'income',
    'stat-pill-posted' => $tone === 'posted',
    'stat-pill-planned' => $tone === 'planned',
    'stat-pill-buffer-pos' => $tone === 'buffer-pos',
    'stat-pill-buffer-neg' => $tone === 'buffer-neg',
    'stat-pill-buffer-empty' => $tone === 'buffer-empty',
    'stat-pill-neutral' => $tone === 'neutral',
]) }}>
    @if ($label !== null)
        <span class="pill-label">{{ $label }}</span>
    @endif
    @if ($value !== null)
        <span class="pill-value">{{ $value }}</span>
    @endif
    {{ $slot }}
</span>
