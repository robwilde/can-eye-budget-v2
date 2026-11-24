@php
    $titles = [
        'duplicates' => __('Possible duplicates'),
        'priceChanges' => __('Price changes'),
        'trials' => __('Trials ending soon'),
        'renewals' => __('Renewals coming up'),
        'overdue' => __('Overdue by a whole cycle'),
        'pausedWithOpen' => __('Paused bills with open occurrences'),
        'pendingDetections' => __('Detected bills awaiting confirmation'),
        'undeclared' => __('Recurring charges not yet declared'),
    ];
    $report = $this->report;
@endphp
<flux:card data-testid="bill-audit">
    <div class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <flux:heading size="lg">{{ __('Bill audit') }}</flux:heading>
                <flux:text size="sm" class="mt-1 text-zinc-500">{{ __('A read-only check of your bills. Fix anything it finds in the editors above.') }}</flux:text>
            </div>
            <flux:input wire:model.live.debounce.500ms="lookback" type="number" min="1" max="24" :label="__('Price changes: look back (months)')" class="w-48" data-testid="audit-lookback" />
        </div>

        @if($this->lookbackError !== null)
            <p class="text-sm text-red-700" role="alert" data-testid="audit-lookback-error">{{ $this->lookbackError }}</p>
        @elseif($report !== null && ! $report->enabled)
            <flux:text data-testid="audit-disabled">{{ __('Recurring is switched off, so there is nothing to audit.') }}</flux:text>
        @elseif($report !== null)
            @foreach($report->sections as $key => $section)
                <div data-testid="audit-{{ $key }}">
                    <flux:heading size="sm">{{ $titles[$key] ?? $key }} <flux:badge size="sm" data-testid="audit-count">{{ $section->count }}</flux:badge></flux:heading>
                    @forelse($section->items as $item)
                        <flux:text size="sm" class="text-zinc-600" wire:key="audit-{{ $key }}-{{ $loop->index }}">{{ $item['label'] }} · {{ $item['detail'] }}</flux:text>
                    @empty
                        <flux:text size="sm" class="text-zinc-400">{{ __('Nothing found.') }}</flux:text>
                    @endforelse
                    @if($section->truncated)
                        <flux:text size="sm" class="text-zinc-500" data-testid="audit-truncated">{{ __('Showing the first :shown of :count.', ['shown' => count($section->items), 'count' => $section->count]) }}</flux:text>
                    @endif
                </div>
            @endforeach
        @endif
    </div>
</flux:card>
