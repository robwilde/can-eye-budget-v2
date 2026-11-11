<div class="space-y-6" data-testid="merchant-manager">
    <div>
        <flux:heading size="xl">{{ __('Merchants') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Name, colour and merge the places you spend. Merged spellings keep resolving to the merchant you keep.') }}</flux:text>
    </div>

    <flux:card>
        <form wire:submit="save" class="space-y-4" data-testid="merchant-form">
            <flux:heading size="lg">{{ $editingId === null ? __('New merchant') : __('Edit merchant') }}</flux:heading>
            <div class="grid gap-4 md:grid-cols-3">
                <flux:input wire:model="name" label="{{ __('Name') }}" data-testid="merchant-name"/>
                <flux:input wire:model="colour" label="{{ __('Colour (hex, optional)') }}" placeholder="#3B82F6" data-testid="merchant-colour"/>
                <flux:input wire:model="website" label="{{ __('Website (optional)') }}" placeholder="example.com" data-testid="merchant-website"/>
            </div>
            <div class="flex items-center gap-3">
                <flux:button type="submit" variant="primary" size="sm" data-testid="merchant-save">{{ $editingId === null ? __('Create') : __('Save') }}</flux:button>
                @if($editingId !== null)
                    <flux:button type="button" variant="ghost" size="sm" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                @endif
            </div>
        </form>
    </flux:card>

    <flux:card>
        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading size="lg">{{ __('Your merchants') }}</flux:heading>
                <flux:input wire:model.live.debounce.300ms="search" size="sm" icon="magnifying-glass" placeholder="{{ __('Search merchants') }}" class="max-w-xs" data-testid="merchant-search"/>
            </div>

            @forelse($merchants as $merchant)
                <div wire:key="merchant-{{ $merchant->id }}" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-neutral-200 px-4 py-3 dark:border-neutral-700" data-testid="merchant-row">
                    <div class="flex min-w-0 items-center gap-3">
                        <flux:checkbox wire:model.live="mergeSources" value="{{ $merchant->id }}" aria-label="{{ __('Select :name to merge', ['name' => $merchant->name]) }}"/>
                        @if(isset($logos[$merchant->merchant_key]))
                            <img src="{{ $logos[$merchant->merchant_key] }}" alt="" class="size-6 rounded" loading="lazy" referrerpolicy="no-referrer"/>
                        @else
                            <span class="inline-block size-4 shrink-0 rounded-full" style="background-color: {{ $merchant->colour }}"></span>
                        @endif
                        <div class="min-w-0">
                            <div class="truncate font-medium">{{ $merchant->name }}</div>
                            <flux:text size="sm" class="truncate text-zinc-500">
                                {{ trans_choice(':count transaction|:count transactions', $merchant->transactions_count) }}@if($merchant->website) · {{ $merchant->website }}@endif
                            </flux:text>
                        </div>
                    </div>
                    <div class="flex items-center gap-1">
                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $merchant->id }})" aria-label="{{ __('Edit') }}"/>
                        <flux:button size="sm" variant="ghost" wire:click="unlink({{ $merchant->id }})" wire:confirm="{{ __('Remove this merchant from all its transactions but keep it?') }}" :disabled="$merchant->transactions_count === 0">{{ __('Unlink') }}</flux:button>
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $merchant->id }})" wire:confirm="{{ __('Delete this merchant? Its transactions stay, with no merchant.') }}" aria-label="{{ __('Delete') }}"/>
                    </div>
                </div>
            @empty
                <flux:text size="sm" class="text-zinc-500" data-testid="merchants-empty">{{ __('No merchants yet.') }}</flux:text>
            @endforelse

            @if(count($mergeSources) > 0)
                <div class="flex flex-wrap items-end gap-3 border-t border-neutral-200 pt-4 dark:border-neutral-700" data-testid="merge-panel">
                    <div class="min-w-56">
                        <flux:select wire:model="mergeTarget" size="sm" label="{{ __('Merge :count selected into', ['count' => count($mergeSources)]) }}" data-testid="merge-target">
                            <flux:select.option value="">{{ __('Choose a merchant') }}</flux:select.option>
                            @foreach($allMerchants as $option)
                                <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="mergeTarget"/>
                        <flux:error name="mergeSources"/>
                    </div>
                    <flux:button variant="primary" size="sm" wire:click="merge" wire:confirm="{{ __('Merge the selected merchants into the chosen one? The selected merchants are removed.') }}" data-testid="merge-submit">{{ __('Merge') }}</flux:button>
                </div>
            @endif
        </div>
    </flux:card>

    <flux:card>
        <div class="space-y-3">
            <flux:heading size="lg">{{ __('Recently unlinked') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ __('Merchants taken off all their transactions in the last :days days.', ['days' => \App\Livewire\MerchantManager::RECENTLY_UNLINKED_DAYS]) }}</flux:text>
            @forelse($recentlyUnlinked as $merchant)
                <div wire:key="unlinked-{{ $merchant->id }}" class="flex items-center justify-between gap-3 rounded-lg border border-neutral-200 px-4 py-3 dark:border-neutral-700" data-testid="recently-unlinked-row">
                    <span class="font-medium">{{ $merchant->name }}</span>
                    <flux:text size="sm" class="text-zinc-500">{{ __('unlinked :when', ['when' => $merchant->unlinked_at->diffForHumans()]) }}</flux:text>
                </div>
            @empty
                <flux:text size="sm" class="text-zinc-500">{{ __('Nothing was unlinked recently.') }}</flux:text>
            @endforelse
        </div>
    </flux:card>
</div>
