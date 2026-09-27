@php
    $pending ??= false;
    $openTransaction ??= false;
@endphp
<div class="flex flex-wrap items-center gap-3">
    <flux:checkbox
        :checked="$line->isChecked()"
        :disabled="$closed"
        wire:click="{{ $line->isChecked() ? 'untick' : 'tick' }}({{ $line->id }})"
        data-testid="reconcile-check-{{ $line->id }}"
    />
    <span class="w-20 text-sm tabular-nums text-fg-3">{{ $line->post_date->format('d/m/Y') }}</span>
    <span class="min-w-0 flex-1 truncate text-sm">
        @if ($openTransaction && $line->transaction_id !== null)
            <button
                type="button"
                class="truncate text-left underline decoration-dotted"
                wire:click="$dispatch('edit-transaction', { id: {{ (int) $line->transaction_id }} })"
                data-testid="reconcile-open-transaction-{{ $line->id }}"
            >{{ $line->description }}</button>
        @else
            {{ $line->description }}
        @endif
    </span>
    @if ($pending)
        <flux:badge size="sm" color="yellow">{{ __('Pending') }}</flux:badge>
    @endif
    <span class="text-sm font-bold tabular-nums">{{ \App\Casts\MoneyCast::format($line->amount) }}</span>
</div>
