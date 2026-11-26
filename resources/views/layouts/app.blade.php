<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        @if (session('status'))
            <flux:callout icon="check-circle" heading="{{ session('status') }}" class="mb-6" />
        @endif

        <x-worker-health-banner />

        @if (session(App\Support\PreviewNotice::SESSION_KEY))
            <flux:callout icon="sparkles" heading="{{ session(App\Support\PreviewNotice::SESSION_KEY) }}" class="mb-6" data-test="preview-notice" />
        @endif

        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
