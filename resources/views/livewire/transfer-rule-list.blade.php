<div>
<flux:card>
    <div class="space-y-4">
        <flux:heading size="lg">Transfer rules <flux:badge size="sm" color="zinc">{{ $rules->count() }}</flux:badge></flux:heading>
        <flux:text size="sm" class="text-zinc-500">Matching imports link automatically. Deleting a rule never changes existing links.</flux:text>

        @forelse($rules as $rule)
            <div wire:key="rule-{{ $rule->id }}" class="flex items-center justify-between gap-3 rounded-lg border border-neutral-200 p-3">
                <div class="min-w-0">
                    <div class="font-medium">{{ $rule->account->name }} → {{ $rule->counterpartAccount->name }}</div>
                    <flux:text size="sm" class="truncate text-zinc-500">
                        “{{ $rule->description_pattern }}” · created {{ $rule->created_at->format('j M Y') }}
                    </flux:text>
                </div>
                <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteRule({{ $rule->id }})" wire:confirm="Delete this rule?"/>
            </div>
        @empty
            <flux:text size="sm" class="text-zinc-500">No transfer rules yet.</flux:text>
        @endforelse
    </div>
</flux:card>
</div>
