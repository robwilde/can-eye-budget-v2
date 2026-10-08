<div>
    @if ($enabled && ($this->pendingOrders->isNotEmpty() || $this->recentlyAutoApproved->isNotEmpty()))
        <x-cib.card class="mb-6" data-testid="bnpl-order-review">
            <x-cib.sec-head :title="__('Buy now pay later orders')">
                @if ($this->pendingOrders->isNotEmpty())
                    <x-cib.stat-pill tone="planned" :value="$this->pendingOrders->flatten(1)->count().' '.__('to review')"/>
                @endif
            </x-cib.sec-head>

            @foreach ($this->pendingOrders as $retailer => $orders)
                <section wire:key="bnpl-retailer-{{ md5($retailer) }}" class="mt-4">
                    <flux:heading size="sm">{{ $retailer }}</flux:heading>
                    @foreach ($orders as $order)
                        <div wire:key="bnpl-order-{{ $order->id }}" class="mt-3 flex flex-wrap items-start justify-between gap-3 border-t border-cib-black/10 pt-3">
                            <div class="min-w-0">
                                <flux:text size="sm" class="text-zinc-500">
                                    {{ $order->provider->label() }}
                                    · {{ $order->instalment_count }} × {{ $formatMoney($order->instalment_amount) }}
                                    · {{ $order->first_due_date->format('j M Y') }} – {{ $order->last_due_date->format('j M Y') }}
                                    @if ($order->isSettled())
                                        · {{ __('Settled') }}
                                    @endif
                                </flux:text>
                                <div class="tabular-nums font-medium">{{ $formatMoney($order->total) }}</div>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <div class="min-w-56">
                                    <x-category-combobox wire:model="categories.{{ $order->id }}" :categories="$categoryOptions" size="sm"/>
                                </div>
                                <flux:button variant="primary" size="sm" wire:click="approve({{ $order->id }})" data-test="bnpl-approve-{{ $order->id }}">{{ __('Approve') }}</flux:button>
                                <flux:button variant="ghost" size="sm" wire:click="reject({{ $order->id }})" data-test="bnpl-reject-{{ $order->id }}">{{ __('Reject') }}</flux:button>
                            </div>
                            @error("categories.{$order->id}")
                                <flux:text size="sm" class="w-full text-red-600">{{ $message }}</flux:text>
                            @enderror
                        </div>
                    @endforeach
                </section>
            @endforeach

            @if ($this->recentlyAutoApproved->isNotEmpty())
                <section class="mt-6">
                    <flux:heading size="sm">{{ __('Auto-approved in the last 30 days') }}</flux:heading>
                    @foreach ($this->recentlyAutoApproved as $order)
                        <div wire:key="bnpl-auto-{{ $order->id }}" class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-cib-black/10 pt-3">
                            <div class="min-w-0">
                                <div class="font-medium">{{ $order->retailer }}</div>
                                <flux:text size="sm" class="text-zinc-500">
                                    {{ $order->provider->label() }} · {{ $formatMoney($order->total) }}
                                </flux:text>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <div class="min-w-56">
                                    <x-category-combobox wire:model="categories.{{ $order->id }}" :categories="$categoryOptions" size="sm"/>
                                </div>
                                <flux:button size="sm" wire:click="recategorise({{ $order->id }})" data-test="bnpl-recategorise-{{ $order->id }}">{{ __('Update category') }}</flux:button>
                            </div>
                            @error("categories.{$order->id}")
                                <flux:text size="sm" class="w-full text-red-600">{{ $message }}</flux:text>
                            @enderror
                        </div>
                    @endforeach
                </section>
            @endif
        </x-cib.card>
    @endif
</div>
