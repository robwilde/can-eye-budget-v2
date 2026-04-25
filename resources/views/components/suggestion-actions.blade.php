@props([
    'acceptMethod',
    'suggestionId',
])

@php
    $acceptCall = $acceptMethod.'('.$suggestionId.')';
    $rejectCall = 'rejectSuggestion('.$suggestionId.')';
@endphp

<div class="flex shrink-0 items-center gap-2">
    <button
        type="button"
        wire:click="{{ $acceptCall }}"
        wire:loading.attr="disabled"
        wire:target="{{ $acceptCall }}"
        class="inline-flex items-center gap-2 rounded-md border-2 border-cib-black bg-cib-yellow-400 px-3 py-1.5 text-sm font-bold text-cib-black shadow-pop-sm transition-transform hover:-translate-y-px disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:translate-y-0"
    >
        <flux:icon.loading wire:loading wire:target="{{ $acceptCall }}" class="size-4"/>
        Accept
    </button>
    <button
        type="button"
        wire:click="{{ $rejectCall }}"
        wire:loading.attr="disabled"
        wire:target="{{ $rejectCall }}"
        class="inline-flex items-center gap-2 rounded-md border-2 border-cib-black bg-cib-white px-3 py-1.5 text-sm font-bold text-cib-black shadow-pop-sm transition-transform hover:-translate-y-px disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:translate-y-0"
    >
        Dismiss
    </button>
</div>
