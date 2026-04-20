@props([
    'tone' => 'default',
])

<div {{ $attributes->class([
    'cib-card',
    'teal' => $tone === 'teal',
    'yellow' => $tone === 'yellow',
]) }}>
    @isset($header)
        <div class="cib-card-header">{{ $header }}</div>
    @endisset
    <div class="cib-card-body">{{ $slot }}</div>
    @isset($footer)
        <div class="cib-card-footer">{{ $footer }}</div>
    @endisset
</div>
