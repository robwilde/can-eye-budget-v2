@can('admin')
    @php($problems = app(App\Services\WorkerHealth::class)->problems())
    @if ($problems !== [])
        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6" data-test="worker-down-banner">
            <flux:callout.heading>{{ __('Background work may be stopped') }}</flux:callout.heading>
            <flux:callout.text>
                {{ implode(' ', array_map(__(...), $problems)) }}
                {{ __('Syncs, imports and other queued work will not run until it is back.') }}
            </flux:callout.text>
        </flux:callout>
    @endif
@endcan
