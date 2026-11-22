@if($view['pages'] > 1)
    <div class="mt-3 flex items-center justify-between gap-3" data-testid="{{ $property }}-pager">
        <flux:button size="sm" variant="subtle" icon="chevron-left" wire:click="$set('{{ $property }}', {{ $view['page'] - 1 }})" :disabled="$view['page'] <= 1" aria-label="Previous page"/>
        <flux:text size="sm">Page {{ $view['page'] }} of {{ $view['pages'] }}</flux:text>
        <flux:button size="sm" variant="subtle" icon="chevron-right" wire:click="$set('{{ $property }}', {{ $view['page'] + 1 }})" :disabled="$view['page'] >= $view['pages']" aria-label="Next page"/>
    </div>
@endif
