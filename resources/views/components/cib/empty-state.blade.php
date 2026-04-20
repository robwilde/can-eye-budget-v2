@props([
    'icon' => null,
    'title',
    'description' => null,
])

<div {{ $attributes->class(['empty-state']) }}>
    @if ($icon)
        <flux:icon :name="$icon" class="mx-auto mb-2"/>
    @endif
    <flux:heading size="lg">{{ $title }}</flux:heading>
    @if ($description)
        <flux:text>{{ $description }}</flux:text>
    @endif
    @isset($action)
        <div class="empty-state-action">{{ $action }}</div>
    @endisset
</div>
