@php use App\Enums\TransactionDirection; @endphp
<div>
    <flux:modal name="category-editor" class="md:w-4xl max-h-[80vh]" variant="default">
        <div class="flex flex-col space-y-4">
            <flux:heading size="lg">{{ __('Categories Editor') }}</flux:heading>

            <div class="flex gap-4" style="min-height: 500px;">
                {{-- Left Panel: Category List --}}
                <div class="flex w-2/5 flex-col gap-3">
                    <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search...') }}" icon="magnifying-glass" size="sm"/>

                    <flux:field variant="inline">
                        <flux:checkbox wire:model.live="showHidden"/>
                        <flux:label>{{ __('Show hidden') }}</flux:label>
                    </flux:field>

                    <div class="flex-1 overflow-y-auto rounded-lg border border-neutral-200 dark:border-neutral-700">
                        <div class="divide-y divide-neutral-200 dark:divide-neutral-700">
                            @forelse($categories as $category)
                                <button
                                        wire:key="cat-{{ $category['id'] }}"
                                        wire:click="selectCategory({{ $category['id'] }})"
                                        class="flex w-full items-center justify-between px-3 py-2 text-left text-sm transition hover:bg-zinc-50 dark:hover:bg-zinc-800 {{ $selectedCategoryId === $category['id'] ? 'bg-amber-50 dark:bg-amber-900/20' : '' }} {{ $category['is_hidden'] ? 'opacity-50' : '' }}"
                                >
                                    <span class="min-w-0 truncate">{{ $category['full_path'] }}</span>
                                    <flux:badge size="sm" color="zinc" class="ml-2 shrink-0">{{ $category['transactions_count'] }}</flux:badge>
                                </button>
                            @empty
                                <div class="p-4 text-center">
                                    <flux:text size="sm">{{ __('No categories found.') }}</flux:text>
                                </div>
                            @endforelse
                        </div>
                    </div>

                    @if($showCreateForm)
                        <div class="space-y-2 rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
                            <flux:input wire:model="newCategoryName" placeholder="{{ __('Category name') }}" size="sm"/>
                            <flux:select wire:model="newParentId" size="sm">
                                <flux:select.option value="">{{ __('Top level (no parent)') }}</flux:select.option>
                                @foreach($parentOptions as $parent)
                                    <flux:select.option value="{{ $parent->id }}">{{ $parent->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <div class="flex gap-2">
                                <flux:button wire:click="createCategory" variant="primary" size="sm">{{ __('Create') }}</flux:button>
                                <flux:button wire:click="$set('showCreateForm', false)" variant="ghost" size="sm">{{ __('Cancel') }}</flux:button>
                            </div>
                        </div>
                    @else
                        <flux:button wire:click="openCreateForm" variant="ghost" size="sm" icon="plus">
                            {{ __('Add Category') }}
                        </flux:button>
                    @endif
                </div>

                {{-- Right Panel: Category Detail & Transactions --}}
                <div class="flex w-3/5 flex-col">
                    @if($selectedCategoryId)
                        <div class="mb-4 flex items-center gap-2">
                            <flux:input wire:model="editingName" size="sm" class="flex-1"/>
                            <flux:button wire:click="saveRename" variant="primary" size="sm">{{ __('Save') }}</flux:button>
                            <flux:button wire:click="toggleHidden({{ $selectedCategoryId }})" variant="ghost" size="sm" icon="eye-slash">
                                {{ __('Hide') }}
                            </flux:button>
                            <flux:button wire:click="confirmDelete({{ $selectedCategoryId }})" variant="ghost" size="sm" icon="trash"
                                         class="text-red-500 hover:text-red-600"/>
                        </div>

                        @if($showDeleteConfirm)
                            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-800 dark:bg-red-900/20">
                                <flux:text size="sm" class="font-medium text-red-700 dark:text-red-400">
                                    {{ __('Delete') }} <strong>{{ $deletingCategoryName }}</strong>?
                                </flux:text>
                                @if($deletingTransactionCount > 0)
                                    <flux:text size="sm" class="mt-1 text-red-600 dark:text-red-500">
                                        {{ __(':count transactions will be uncategorized.', ['count' => $deletingTransactionCount]) }}
                                    </flux:text>
                                @endif
                                <div class="mt-2 flex gap-2">
                                    <flux:button wire:click="deleteCategory" variant="danger" size="sm">{{ __('Confirm Delete') }}</flux:button>
                                    <flux:button wire:click="$set('showDeleteConfirm', false)" variant="ghost" size="sm">{{ __('Cancel') }}</flux:button>
                                </div>
                            </div>
                        @endif

                        <flux:text size="sm" class="mb-2 font-medium">{{ __('Most recent transactions:') }}</flux:text>

                        <div class="flex-1 overflow-y-auto rounded-lg border border-neutral-200 dark:border-neutral-700">
                            <div class="divide-y divide-neutral-200 dark:divide-neutral-700">
                                @forelse($transactions as $transaction)
                                    <div wire:key="txn-{{ $transaction->id }}" class="flex items-center justify-between px-3 py-2 text-sm">
                                        <div class="flex items-center gap-3">
                                            <flux:text size="sm" class="tabular-nums text-zinc-500">{{ $transaction->post_date->format('Y-m-d') }}</flux:text>
                                            <flux:text size="sm" class="truncate">{{ $transaction->description }}</flux:text>
                                        </div>
                                        <flux:text size="sm"
                                                   class="tabular-nums font-medium {{ $transaction->direction === TransactionDirection::Debit ? 'text-red-600 dark:text-red-500' : 'text-green-600 dark:text-green-500' }}">
                                            {{ $formatMoney($transaction->amount) }}
                                        </flux:text>
                                    </div>
                                @empty
                                    <div class="p-4 text-center">
                                        <flux:text size="sm">{{ __('No transactions for this category.') }}</flux:text>
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    @else
                        <div class="flex flex-1 items-center justify-center">
                            <div class="text-center">
                                <flux:icon.tag class="mx-auto size-12 text-zinc-400"/>
                                <flux:text class="mt-2">{{ __('Select a category to view its transactions.') }}</flux:text>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </flux:modal>
</div>
