@props([
    'title',
    'href' => null,
])

<div {{ $attributes->class(['sec-head']) }}>
    <h3>{{ $title }}</h3>
    @if ($href)
        <a class="link" href="{{ $href }}">See all →</a>
    @endif
</div>
