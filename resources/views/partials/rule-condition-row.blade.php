@php
    use App\Enums\RuleTriggerField;
    use App\Enums\RuleTriggerOperator;
@endphp
<div class="flex items-start gap-2 rounded-lg border border-neutral-200 p-2 dark:border-neutral-700">
    <flux:field class="flex-1">
        <flux:select wire:model="{{ $path }}.field" size="sm">
            <flux:select.option value="">Field...</flux:select.option>
            @foreach(RuleTriggerField::cases() as $field)
                <flux:select.option value="{{ $field->value }}">{{ $field->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:error name="{{ $path }}.field" />
    </flux:field>

    <flux:field class="flex-1">
        <flux:select wire:model.live="{{ $path }}.operator" size="sm">
            <flux:select.option value="">Operator...</flux:select.option>
            @foreach(RuleTriggerOperator::cases() as $operator)
                <flux:select.option value="{{ $operator->value }}">{{ $operator->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:error name="{{ $path }}.operator" />
    </flux:field>

    @if(! isset($condition['operator']) || $condition['operator'] === '' || RuleTriggerOperator::tryFrom($condition['operator'])?->requiresValue() !== false)
        <flux:field class="flex-1">
            <flux:input wire:model="{{ $path }}.value" size="sm" placeholder="Value" />
            <flux:error name="{{ $path }}.value" />
        </flux:field>
    @endif

    <flux:button variant="ghost" size="sm" wire:click="{{ $remove }}">
        <flux:icon.x-mark class="size-4" />
    </flux:button>
</div>
