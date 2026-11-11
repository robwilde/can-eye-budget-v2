<div class="space-y-6" data-test="tag-manager">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">{{ __('Tags') }}</flux:heading>
        <div class="flex items-center gap-2">
            @if ($tags->isNotEmpty())
                <button type="button" wire:click="confirmDeleteAll" class="text-sm font-bold underline decoration-dotted"
                        data-test="tag-delete-all">{{ __('Delete all') }}</button>
            @endif
            <button type="button" wire:click="openCreateModal" class="cib-yellow-pill" data-test="tag-add">
                <flux:icon.plus class="size-4"/>
                {{ __('Add tag') }}
            </button>
        </div>
    </div>

    @if ($tags->isEmpty())
        <flux:text data-test="tag-empty">{{ __('No tags yet. Add one to start labelling transactions.') }}</flux:text>
    @else
        <section class="agenda-group">
            <div class="day-card">
                @foreach ($tags as $tag)
                    <div wire:key="tag-{{ $tag->id }}" class="flex items-center justify-between gap-3 px-4 py-3" data-test="tag-row-{{ $tag->id }}">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="size-4 shrink-0 rounded-full border-2 border-cib-black" style="background-color: {{ $tag->colour }}"></span>
                            <span class="truncate font-bold">{{ $tag->name }}</span>
                            <x-cib.stat-pill tone="neutral" data-test="tag-count-{{ $tag->id }}">{{ trans_choice(':count transaction|:count transactions', $tag->transactions_count) }}</x-cib.stat-pill>
                        </div>
                        <div class="flex items-center gap-1">
                            <flux:button variant="ghost" size="sm" icon="pencil-square" wire:click="openEditModal({{ $tag->id }})"
                                         :aria-label="__('Edit')" data-test="tag-edit-{{ $tag->id }}"/>
                            <flux:button variant="ghost" size="sm" icon="trash" wire:click="confirmDelete({{ $tag->id }})"
                                         :aria-label="__('Delete')" data-test="tag-delete-{{ $tag->id }}"/>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <flux:modal wire:model="showFormModal" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ $editingTagId ? __('Edit tag') : __('Add tag') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input wire:model="name" maxlength="50" data-test="tag-name"/>
                <flux:error name="name"/>
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Colour') }}</flux:label>
                <div class="flex flex-wrap gap-2" data-test="tag-palette">
                    @foreach ($palette as $swatch)
                        <button type="button" wire:click="$set('colour', '{{ $swatch }}')"
                                class="size-7 rounded-full border-2 {{ strtoupper($colour) === $swatch ? 'border-cib-black ring-2 ring-offset-1' : 'border-transparent' }}"
                                style="background-color: {{ $swatch }}" aria-label="{{ $swatch }}"></button>
                    @endforeach
                </div>
                <flux:error name="colour"/>
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showFormModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="saveTag" data-test="tag-save">{{ $editingTagId ? __('Update') : __('Create') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="showDeleteModal" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Delete tag') }}</flux:heading>
            <flux:text>
                {{ __('Delete :name? It is removed from every transaction that carries it, or you can move those transactions to another tag first.', ['name' => $deletingTagName]) }}
            </flux:text>

            <flux:field>
                <flux:label>{{ __('Replace with') }}</flux:label>
                <flux:select wire:model="replacementTagId" data-test="tag-replacement">
                    <flux:select.option value="">{{ __('No replacement') }}</flux:select.option>
                    @foreach ($tags->where('id', '!=', $deletingTagId) as $option)
                        <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="replacementTagId"/>
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeDeleteModal">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="deleteTag" data-test="tag-delete-confirm">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model="showDeleteAllModal" class="max-w-sm">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Delete all tags') }}</flux:heading>
            <flux:text>{{ __('This deletes all :count of your tags and removes them from every transaction.', ['count' => $tags->count()]) }}</flux:text>
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showDeleteAllModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="deleteAllTags" data-test="tag-delete-all-confirm">{{ __('Delete all') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
