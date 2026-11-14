<div class="space-y-6" data-testid="insight-feed" @if($checkingSince !== null) wire:poll.2s="pollRefresh" @endif>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl">{{ __('Insights') }}</flux:heading>
            <flux:text class="mt-1">{{ __('What changed in your money, and what needs a look.') }}</flux:text>
        </div>
        <flux:button wire:click="checkForNew" :disabled="$checkingSince !== null" icon="arrow-path" size="sm" data-testid="insights-check">
            {{ $checkingSince !== null ? __('Checking…') : __('Check for new insights') }}
        </flux:button>
    </div>

    @if ($timedOut)
        <flux:text size="sm" data-testid="insights-timeout">{{ __('This is taking longer than usual. New insights will appear here once the check finishes; you can also check again.') }}</flux:text>
    @endif

    <div class="sr-only" role="status" aria-live="polite" wire:ignore data-testid="insight-announcement"
         x-data x-on:insight-dismissed.window="$el.textContent = ''; setTimeout(() => $el.textContent = @js(__('Insight dismissed')), 100)"></div>

    @forelse ($this->insights as $insight)
        <x-cib.card wire:key="insight-{{ $insight->id }}" data-testid="insight-row">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge size="sm" :color="match ($insight->priority->value) { 'high' => 'red', 'medium' => 'yellow', default => 'zinc' }">{{ \Illuminate\Support\Str::headline($insight->type->value) }}</flux:badge>
                        @if (in_array($insight->id, $newIds, true))
                            <flux:badge size="sm" color="lime" data-testid="insight-new">{{ __('New') }}</flux:badge>
                        @endif
                    </div>
                    <flux:heading size="lg">{{ $insight->title }}</flux:heading>
                    <flux:text size="sm">{{ $insight->body }}</flux:text>
                    @isset($this->links[$insight->id])
                        <a href="{{ $this->links[$insight->id]['url'] }}" wire:navigate class="inline-block text-sm font-bold underline decoration-dotted" data-testid="insight-link">{{ $this->links[$insight->id]['label'] }}</a>
                    @endisset
                </div>
                <flux:button wire:click="acknowledge({{ $insight->id }})" size="sm" variant="ghost" data-testid="insight-got-it">{{ __('Got it') }}</flux:button>
            </div>
        </x-cib.card>
    @empty
        <x-cib.empty-state icon="light-bulb" :title="__('No insights right now')" :description="__('New ones show up here when something changes.')" data-testid="insights-empty"/>
    @endforelse
</div>
