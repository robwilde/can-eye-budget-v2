<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        @if (session('status'))
            <flux:callout icon="check-circle" heading="{{ session('status') }}" class="mb-6" />
        @endif

        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
