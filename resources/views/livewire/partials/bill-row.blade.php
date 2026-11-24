@use('App\Casts\MoneyCast')
<div class="flex flex-wrap items-center gap-3 border-b border-neutral-200 py-2 text-sm" wire:key="bill-{{ $row->planId }}-{{ $row->occurrenceId ?? 'none' }}" data-testid="bill-{{ $row->planId }}">
    <span class="w-24 tabular-nums">{{ $row->dueOn->format('Y-m-d') }}</span>
    <span class="min-w-[8rem] flex-1">{{ $row->name }}</span>
    <flux:badge size="sm" :color="$stateColor($row->state)" data-testid="bill-state">{{ $row->state->label() }}</flux:badge>
    <span class="w-24 text-right tabular-nums" data-testid="bill-amount">{{ MoneyCast::format($row->remaining > 0 ? $row->remaining : $row->expected) }}</span>
    @if($row->occurrenceId !== null)
        @if(isset($this->reviewOccurrences[$row->occurrenceId]))
            <flux:button size="sm" variant="ghost" href="{{ route('rules', ['account' => $this->reviewOccurrences[$row->occurrenceId]]) }}" wire:navigate data-testid="bill-review">{{ __('Review') }}</flux:button>
        @else
            <flux:button size="sm" variant="ghost" wire:click="$dispatch('open-occurrence-payment-panel', { occurrenceId: {{ $row->occurrenceId }} })" data-testid="bill-find">{{ __('Find') }}</flux:button>
        @endif
    @endif
</div>
