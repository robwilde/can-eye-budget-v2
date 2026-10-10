<div class="space-y-6" data-test="confirm-budget-tags-step">
    <div>
        <flux:heading size="lg">{{ __('Needs, wants and savings') }}</flux:heading>
        <flux:subheading>
            {{ __('Each spending category counts as a need, a want or savings. Move any that do not fit how you live, or skip to keep these defaults.') }}
        </flux:subheading>
    </div>

    @foreach (\App\Enums\BudgetTag::cases() as $tag)
        <div class="space-y-2" data-test="budget-tag-group-{{ $tag->value }}">
            <flux:heading size="sm">{{ $tag->label() }}</flux:heading>

            @forelse ($this->groups[$tag->value] as $row)
                <div class="flex items-center justify-between gap-4" wire:key="budget-tag-{{ $row['category']->id }}">
                    <flux:text>{{ $row['category']->name }}</flux:text>
                    <flux:select
                        size="sm"
                        class="max-w-36"
                        wire:change="setTag({{ $row['category']->id }}, $event.target.value)"
                        :value="$tag->value"
                        data-test="budget-tag-select-{{ $row['category']->id }}"
                    >
                        @foreach (\App\Enums\BudgetTag::cases() as $option)
                            <flux:select.option value="{{ $option->value }}" :selected="$option === $tag">{{ $option->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @empty
                <flux:text size="sm" class="text-zinc-500">{{ __('Nothing here.') }}</flux:text>
            @endforelse
        </div>
    @endforeach

    <div class="flex items-center gap-3">
        <flux:button wire:click="confirm" variant="primary" data-test="budget-tags-confirm">
            {{ __('Looks good') }}
        </flux:button>
        <flux:button wire:click="skip" variant="ghost" data-test="budget-tags-skip">
            {{ __('Skip, keep the defaults') }}
        </flux:button>
    </div>
</div>
