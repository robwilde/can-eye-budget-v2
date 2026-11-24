@use('App\Casts\MoneyCast')
<div data-testid="upcoming-recurring">
    @if($this->items !== [])
        <flux:card class="space-y-2">
            <flux:heading size="sm">{{ __('Coming up in the next 10 days') }}</flux:heading>
            @foreach($this->items as $item)
                <div class="flex flex-wrap items-center gap-3 text-sm" wire:key="upcoming-{{ $item->planId }}-{{ $item->dueOn->format('Y-m-d') }}" data-testid="upcoming-item-{{ $item->planId }}">
                    <span class="w-24 tabular-nums">{{ $item->dueOn->format('Y-m-d') }}</span>
                    <span class="min-w-[8rem] flex-1">{{ $item->name }}</span>
                    <span class="tabular-nums" data-testid="upcoming-amount">
                        @if($item->low !== null && $item->high !== null)
                            {{ MoneyCast::format($item->low) }}–{{ MoneyCast::format($item->high) }}
                        @else
                            {{ MoneyCast::format($item->expected) }}
                        @endif
                    </span>
                    <flux:badge size="sm" :color="$item->income ? 'green' : 'zinc'">{{ $item->income ? __('Income') : __('Bill') }}</flux:badge>
                </div>
            @endforeach
        </flux:card>
    @endif
</div>
