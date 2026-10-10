@php
    use App\Casts\MoneyCast;
    use App\Enums\BudgetTag;
@endphp

<flux:card>
    <div class="space-y-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading size="lg">Check your biggest payees</flux:heading>
                <flux:text size="sm" class="mt-1 text-zinc-500">
                    One answer per payee sets the category for every matching transaction and keeps future ones the same. Biggest spend first.
                </flux:text>
            </div>

            <div class="flex items-center gap-2">
                @if($rows->isNotEmpty())
                    <flux:button size="sm" variant="primary" wire:click="acceptAll" wire:loading.attr="disabled" wire:target="acceptAll">
                        Accept all suggested
                    </flux:button>
                @endif

                @if($onboarding)
                    <flux:button size="sm" variant="filled" wire:click="finish" data-test="payee-review-skip">
                        {{ $rows->isEmpty() ? 'Continue' : 'Skip for now' }}
                    </flux:button>
                @else
                    <flux:button size="sm" variant="ghost" wire:click="finish" data-test="payee-review-done">Done</flux:button>
                @endif
            </div>
        </div>

        @forelse($rows as $row)
            @php($item = $row['item'])
            <div wire:key="payee-review-{{ $item->payeeId }}" class="rounded-lg border border-neutral-200 p-4">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:heading size="sm">{{ $item->merchantName }}</flux:heading>
                            <flux:badge size="sm" color="zinc">{{ $item->transactionCount }} {{ \Illuminate\Support\Str::plural('transaction', $item->transactionCount) }}</flux:badge>
                            @if($row['tag'] !== null)
                                <flux:badge size="sm" color="{{ match ($row['tag']) {
                                    BudgetTag::Needs => 'blue',
                                    BudgetTag::Wants => 'amber',
                                    BudgetTag::Savings => 'green',
                                } }}" data-test="payee-tag">{{ $row['tag']->label() }}</flux:badge>
                            @endif
                        </div>

                        <flux:text size="sm" class="font-bold tabular-nums text-zinc-900">
                            {{ MoneyCast::format(abs($item->typicalAmount)) }}
                        </flux:text>

                        @foreach($item->rawDescriptions as $description)
                            <flux:text size="xs" class="truncate text-zinc-500">{{ $description }}</flux:text>
                        @endforeach
                    </div>

                    <div class="flex flex-col gap-3 lg:w-96">
                        @if($item->isAmbiguous)
                            <flux:radio.group wire:model.live="intents.{{ $item->payeeId }}" variant="segmented" size="sm" label="Is this for work or personal?">
                                <flux:radio value="work" label="Work" />
                                <flux:radio value="personal" label="Personal" />
                            </flux:radio.group>
                        @endif

                        <flux:select
                            wire:model.live="categoryChoices.{{ $item->payeeId }}"
                            wire:key="payee-category-{{ $item->payeeId }}-{{ $intents[$item->payeeId] ?? 'any' }}"
                            size="sm"
                            placeholder="Choose a category"
                        >
                            @foreach($row['categories'] as $category)
                                <flux:select.option value="{{ $category->id }}">{{ $category->fullPath() }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model.live="tagChoices.{{ $item->payeeId }}" size="sm">
                            <flux:select.option value="">Default tag</flux:select.option>
                            @foreach($tags as $tag)
                                <flux:select.option value="{{ $tag->value }}">{{ $tag->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <div class="flex items-center gap-2">
                            <flux:button
                                variant="primary"
                                size="sm"
                                wire:click="accept({{ $item->payeeId }})"
                                wire:loading.attr="disabled"
                                wire:target="accept({{ $item->payeeId }})"
                            >
                                Accept
                            </flux:button>
                            <flux:button
                                variant="ghost"
                                size="sm"
                                wire:click="dismiss({{ $item->payeeId }})"
                                wire:loading.attr="disabled"
                                wire:target="dismiss({{ $item->payeeId }})"
                            >
                                Dismiss
                            </flux:button>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="flex flex-col items-center justify-center rounded-lg border border-dashed border-neutral-200 py-10 text-center">
                <flux:icon.check-circle class="mb-3 size-10 text-zinc-400" />
                <flux:heading size="sm">Nothing to review. Every payee is sorted.</flux:heading>
            </div>
        @endforelse
    </div>
</flux:card>
