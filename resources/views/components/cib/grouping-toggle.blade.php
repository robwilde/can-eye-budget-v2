@props(['grouping'])

<div class="flex gap-1" role="group" aria-label="{{ __('Group by') }}" data-test="cash-flow-grouping">
    @foreach (\App\Enums\CashFlowGrouping::cases() as $option)
        <button type="button" wire:click="setGrouping('{{ $option->value }}')" aria-pressed="{{ $option === $grouping ? 'true' : 'false' }}" data-test="cash-flow-grouping-{{ $option->value }}" @class(['rounded border px-2 py-1 text-sm', 'bg-zinc-900 text-white' => $option === $grouping, 'border-zinc-300' => $option !== $grouping])>{{ __($option->label()) }}</button>
    @endforeach
</div>
