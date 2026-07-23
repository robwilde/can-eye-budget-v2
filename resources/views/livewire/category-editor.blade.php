@php use App\Enums\TransactionDirection; @endphp
<div>
    <x-cib.card>
        <div class="flex flex-col space-y-4">
            <div class="flex items-center justify-between gap-3">
                <flux:heading size="lg">{{ __('Categories') }}</flux:heading>
                @unless($showCreateForm)
                    <button type="button" wire:click="openCreateForm" class="cib-yellow-pill">
                        <flux:icon.plus class="size-4"/>
                        {{ __('Add category') }}
                    </button>
                @endunless
            </div>

            @if($showCreateForm)
                <div class="space-y-2 rounded-lg border-2 border-[var(--color-border-strong)] p-3">
                    <flux:input wire:model="newCategoryName" placeholder="{{ __('Category name') }}" size="sm"/>
                    <flux:select wire:model="newParentId" size="sm">
                        <flux:select.option value="">{{ __('Top level (no parent)') }}</flux:select.option>
                        @foreach($parentOptions as $parent)
                            <flux:select.option value="{{ $parent->id }}">{{ $parent->fullPath() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <div class="flex gap-2">
                        <flux:button wire:click="createCategory" variant="primary" size="sm">{{ __('Create') }}</flux:button>
                        <flux:button wire:click="$set('showCreateForm', false)" variant="ghost" size="sm">{{ __('Cancel') }}</flux:button>
                    </div>
                </div>
            @endif

            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search...') }}" icon="magnifying-glass" size="sm" class="sm:max-w-xs"/>
                <flux:field variant="inline">
                    <flux:checkbox wire:model.live="showHidden"/>
                    <flux:label>{{ __('Show hidden') }}</flux:label>
                </flux:field>
            </div>

            <div class="overflow-hidden rounded-lg border-2 border-[var(--color-border-strong)]">
                @forelse($categories as $category)
                    <button
                        type="button"
                        wire:key="cat-{{ $category['id'] }}"
                        wire:click="selectCategory({{ $category['id'] }})"
                        @class([
                            'cat-list-row w-full',
                            'is-active' => $selectedCategoryId === $category['id'],
                            'is-hidden' => $category['is_hidden'],
                            'cat-group-head' => ! $isSearching && $category['depth'] === 0,
                        ])
                        style="{{ $isSearching ? '' : 'padding-left: '.(0.875 + $category['depth'] * 1.25).'rem' }}"
                    >
                        <span class="flex min-w-0 items-center gap-2">
                            <flux:icon.chevron-right @class(['size-4 shrink-0 transition-transform', 'rotate-90' => $selectedCategoryId === $category['id']])/>
                            <span class="cat-name">{{ $isSearching ? $category['full_path'] : $category['name'] }}</span>
                        </span>
                        <span class="cat-count">{{ $category['transactions_count'] }}</span>
                    </button>

                    @if($selectedCategoryId === $category['id'])
                        <div wire:key="cat-detail-{{ $category['id'] }}" class="space-y-3 border-b-2 border-[var(--color-border-strong)] bg-[var(--color-cib-n-50)] px-4 py-3">
                            <div class="flex flex-wrap items-end gap-2">
                                <div class="flex-1">
                                    <label class="cib-label" for="category-name-input">{{ __('Category name') }}</label>
                                    <flux:input id="category-name-input" wire:model="editingName" size="sm"/>
                                </div>
                                <div class="flex-1">
                                    <label class="cib-label" for="category-parent-select">{{ __('Parent category') }}</label>
                                    <flux:select id="category-parent-select" wire:model="editingParentId" size="sm">
                                        <flux:select.option value="">{{ __('Top level (no parent)') }}</flux:select.option>
                                        @foreach($parentOptions as $parent)
                                            @continue($parent->id === $selectedCategoryId)
                                            <flux:select.option value="{{ $parent->id }}">{{ $parent->fullPath() }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    @error('editingParentId')
                                        <flux:text size="sm" class="mt-1 text-red-600">{{ $message }}</flux:text>
                                    @enderror
                                </div>
                                <flux:button wire:click="saveRename" variant="primary" size="sm">{{ __('Save') }}</flux:button>
                                <flux:button wire:click="toggleHidden({{ $category['id'] }})" variant="ghost" size="sm" icon="eye-slash">
                                    {{ $category['is_hidden'] ? __('Unhide') : __('Hide') }}
                                </flux:button>
                                <flux:button wire:click="confirmDelete({{ $category['id'] }})" variant="ghost" size="sm" icon="trash" class="text-red-500 hover:text-red-600"/>
                            </div>

                            @if($showDeleteConfirm)
                                <div class="rounded-lg border-2 border-red-300 bg-red-50 p-3">
                                    <flux:text size="sm" class="font-medium text-red-700">
                                        {{ __('Delete') }} <strong>{{ $deletingCategoryName }}</strong>?
                                    </flux:text>
                                    @if($deletingTransactionCount > 0)
                                        <flux:text size="sm" class="mt-1 text-red-600">
                                            {{ __(':count transactions will be uncategorized.', ['count' => $deletingTransactionCount]) }}
                                        </flux:text>
                                    @endif
                                    <div class="mt-2 flex gap-2">
                                        <flux:button wire:click="deleteCategory" variant="danger" size="sm">{{ __('Confirm Delete') }}</flux:button>
                                        <flux:button wire:click="$set('showDeleteConfirm', false)" variant="ghost" size="sm">{{ __('Cancel') }}</flux:button>
                                    </div>
                                </div>
                            @endif

                            <flux:text size="sm" class="font-medium">{{ __('Most recent transactions:') }}</flux:text>
                            <div class="divide-y divide-neutral-200">
                                @forelse($transactions as $transaction)
                                    <div wire:key="txn-{{ $transaction->id }}" class="flex items-center justify-between py-2 text-sm">
                                        <div class="flex min-w-0 items-center gap-3">
                                            <flux:text size="sm" class="tabular-nums text-zinc-500">{{ $transaction->post_date->format('Y-m-d') }}</flux:text>
                                            <flux:text size="sm" class="truncate">{{ $transaction->description }}</flux:text>
                                        </div>
                                        <flux:text size="sm" class="tabular-nums font-medium {{ $transaction->direction === TransactionDirection::Debit ? 'text-red-600' : 'text-green-600' }}">
                                            {{ $formatMoney($transaction->amount) }}
                                        </flux:text>
                                    </div>
                                @empty
                                    <flux:text size="sm" class="py-2">{{ __('No transactions for this category.') }}</flux:text>
                                @endforelse
                            </div>
                        </div>
                    @endif
                @empty
                    <div class="p-4 text-center">
                        <flux:text size="sm">{{ __('No categories found.') }}</flux:text>
                    </div>
                @endforelse
            </div>
        </div>
    </x-cib.card>
</div>
