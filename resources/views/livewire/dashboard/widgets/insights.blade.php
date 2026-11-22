@if ($dashboard->insights->isNotEmpty())
    <x-cib.card data-test="dashboard-insights-card">
        <flux:heading size="lg">{{ __('Insights') }}</flux:heading>
        <ul class="mt-3 space-y-2">
            @foreach ($dashboard->insights as $insight)
                <li wire:key="dashboard-insight-{{ $insight->id }}" data-test="dashboard-insight">
                    <div class="text-sm font-bold">{{ $insight->title }}</div>
                    <flux:text size="sm">{{ $insight->body }}</flux:text>
                </li>
            @endforeach
        </ul>
        <a href="{{ route('insights') }}" wire:navigate
           class="mt-3 inline-block text-sm font-bold underline decoration-dotted"
           data-test="dashboard-insights-link">{{ __('View all insights') }}</a>
    </x-cib.card>
@endif
