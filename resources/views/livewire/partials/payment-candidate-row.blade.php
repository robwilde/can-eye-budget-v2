@php
    use App\Casts\MoneyCast;
    $transaction = $candidate->transaction;
@endphp
<div class="flex flex-wrap items-center gap-3 border-b border-neutral-200 py-2 text-sm" wire:key="candidate-{{ $section }}-{{ $transaction->id }}" data-testid="candidate-{{ $transaction->id }}">
    <span class="w-24 tabular-nums">{{ $transaction->post_date->format('Y-m-d') }}</span>
    <span class="min-w-[10rem] flex-1" title="{{ $transaction->description }}">{{ $transaction->description }}</span>
    <span class="w-24 text-right tabular-nums">{{ MoneyCast::format(abs($transaction->amount)) }}</span>
    @if($candidate->free !== abs($transaction->amount))
        <span class="text-xs text-zinc-500" data-testid="candidate-free">{{ __(':amount left', ['amount' => MoneyCast::format($candidate->free)]) }}</span>
    @endif
    @if($candidate->score !== null)
        <span class="order-last flex w-full flex-wrap gap-1 text-xs" data-testid="candidate-signals">
            <flux:badge size="sm" color="green">{{ (int) round($candidate->score->total() * 100) }}%</flux:badge>
            <flux:badge size="sm">{{ __('Name') }} {{ number_format($candidate->score->identity, 2) }}</flux:badge>
            <flux:badge size="sm">{{ __('Amount') }} {{ number_format($candidate->score->amount, 2) }}</flux:badge>
            <flux:badge size="sm">{{ __('Date') }} {{ number_format($candidate->score->date, 2) }}</flux:badge>
            <flux:badge size="sm">{{ __('Account') }} {{ number_format($candidate->score->account, 2) }}</flux:badge>
        </span>
    @endif
    <input type="text" inputmode="decimal" class="w-20 rounded border border-neutral-300 px-1 py-0.5" wire:model="customAmounts.{{ $transaction->id }}" placeholder="{{ __('All') }}" aria-label="{{ __('Amount to apply') }}" data-testid="candidate-amount">
    <flux:button size="sm" wire:click="attach({{ $transaction->id }})" data-testid="candidate-attach">{{ __('Attach') }}</flux:button>
</div>
