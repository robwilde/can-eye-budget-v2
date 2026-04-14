@props([
    'categories' => [],
    'label' => null,
    'placeholder' => 'No category',
    'size' => null,
])

@php
    $wireModel = $attributes->whereStartsWith('wire:model')->first();
    $isSmall = $size === 'sm' || $size === 'xs';

    $items = collect($categories)->map(fn ($c) => [
        'id' => $c->id,
        'label' => $c->fullPath(),
    ])->values()->all();
@endphp

<div
    x-data="{
        open: false,
        search: '',
        selectedId: null,
        selectedLabel: '',
        items: {{ Js::from($items) }},
        wireModel: '{{ $wireModel }}',

        init() {
            const currentValue = this.$wire.get(this.wireModel);
            if (currentValue) {
                const match = this.items.find(i => i.id == currentValue);
                if (match) {
                    this.selectedId = match.id;
                    this.selectedLabel = match.label;
                    this.search = match.label;
                }
            }

            this.$watch('selectedId', (value) => {
                this.$wire.set(this.wireModel, value);
            });
        },

        get filtered() {
            if (this.search.length < 3) return [];
            const term = this.search.toLowerCase();
            return this.items.filter(i => i.label.toLowerCase().includes(term));
        },

        select(item) {
            this.selectedId = item.id;
            this.selectedLabel = item.label;
            this.search = item.label;
            this.closeDropdown();
        },

        clear() {
            this.selectedId = null;
            this.selectedLabel = '';
            this.search = '';
            this.closeDropdown();
        },

        onInput() {
            this.openDropdown();
            if (this.selectedId && this.search !== this.selectedLabel) {
                this.selectedId = null;
                this.selectedLabel = '';
            }
        },

        openDropdown() {
            if (this.open) return;
            this.open = true;
            this.$nextTick(() => {
                const el = this.$refs.dropdown;
                if (el && typeof el.showPopover === 'function') {
                    const rect = this.$refs.input.getBoundingClientRect();
                    el.style.position = 'fixed';
                    el.style.top = (rect.bottom + 4) + 'px';
                    el.style.left = rect.left + 'px';
                    el.style.width = rect.width + 'px';
                    el.showPopover();
                }
            });
        },

        closeDropdown() {
            if (!this.open) return;
            this.open = false;
            const el = this.$refs.dropdown;
            if (el && typeof el.hidePopover === 'function') {
                try { el.hidePopover(); } catch(e) {}
            }
        },
    }"
    {{ $attributes->except(['wire:model', 'wire:model.live', 'wire:model.blur', 'wire:model.defer', 'categories', 'label', 'placeholder', 'size'])->class('relative') }}
    role="combobox"
    aria-haspopup="listbox"
    :aria-expanded="open"
>
    @if($label)
        <label class="inline-flex text-sm font-medium text-zinc-800 dark:text-white mb-1">
            {{ $label }}
        </label>
    @endif

    <div class="relative">
        <input
            type="text"
            x-ref="input"
            x-model="search"
            @input="onInput()"
            @focus="if (search.length >= 3) openDropdown()"
            @keydown.escape.prevent="closeDropdown()"
            placeholder="{{ $placeholder }}"
            autocomplete="off"
            @class([
                'appearance-none w-full block ps-3 pe-8',
                'border border-zinc-200 border-b-zinc-300/80 dark:border-white/10',
                'bg-white dark:bg-white/10',
                'text-zinc-700 dark:text-zinc-300',
                'shadow-xs',
                'placeholder:text-zinc-400 dark:placeholder:text-zinc-400',
                'focus:outline-2 focus:-outline-offset-1 focus:outline-zinc-800/20 dark:focus:outline-white/30',
                'h-10 py-2 text-base sm:text-sm leading-[1.375rem] rounded-lg' => !$isSmall,
                'h-8 py-1.5 text-sm leading-[1.125rem] rounded-md' => $isSmall,
            ])
        />

        <button
            x-show="selectedId"
            @click.prevent="clear()"
            type="button"
            class="absolute inset-y-0 end-0 flex items-center pe-2 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200"
            aria-label="Clear selection"
        >
            <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
            </svg>
        </button>
    </div>

    <div
        x-ref="dropdown"
        popover="manual"
        @click.outside="closeDropdown()"
        class="m-0 max-h-60 overflow-auto rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-white/10 dark:bg-zinc-800"
        role="listbox"
    >
        <template x-if="open && search.length < 3">
            <div class="px-3 py-2 text-sm text-zinc-400">
                Type 3+ characters to search...
            </div>
        </template>

        <template x-if="open && search.length >= 3 && filtered.length === 0">
            <div class="px-3 py-2 text-sm text-zinc-400">
                No categories found
            </div>
        </template>

        <template x-for="item in filtered" :key="item.id">
            <button
                type="button"
                @click="select(item)"
                class="flex w-full cursor-pointer items-center px-3 py-1.5 text-left text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/10"
                :class="{ 'bg-zinc-50 dark:bg-white/5': selectedId === item.id }"
                role="option"
                :aria-selected="selectedId === item.id"
                x-text="item.label"
            ></button>
        </template>
    </div>
</div>
