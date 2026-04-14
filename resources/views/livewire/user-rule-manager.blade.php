@php
    use App\Enums\RuleActionType;
    use App\Enums\RuleTriggerField;
    use App\Enums\RuleTriggerOperator;
    use Illuminate\Support\Str;
@endphp
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="lg">Rules</flux:heading>
        <flux:button variant="primary" size="sm" wire:click="openAddGroupModal">Add Group</flux:button>
    </div>

    @forelse($groups as $group)
        <flux:card wire:key="group-{{ $group->id }}">
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="flex flex-col gap-1">
                        <div class="flex items-center gap-1">
                            <flux:button variant="ghost" size="xs" wire:click="moveGroupUp({{ $group->id }})">
                                <flux:icon.chevron-up class="size-3" />
                            </flux:button>
                            <flux:button variant="ghost" size="xs" wire:click="moveGroupDown({{ $group->id }})">
                                <flux:icon.chevron-down class="size-3" />
                            </flux:button>
                        </div>
                    </div>
                    <div>
                        <flux:heading size="sm">{{ $group->name }}</flux:heading>
                        @if($group->description)
                            <flux:text size="sm" class="text-zinc-500">{{ $group->description }}</flux:text>
                        @endif
                    </div>
                    <flux:badge size="sm" color="zinc">{{ $group->rules->count() }} {{ Str::plural('rule', $group->rules->count()) }}</flux:badge>
                    @unless($group->is_active)
                        <flux:badge size="sm" color="yellow">Inactive</flux:badge>
                    @endunless
                    @if($group->stop_processing)
                        <flux:badge size="sm" color="red">Stop Processing</flux:badge>
                    @endif
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <flux:button variant="ghost" size="sm" wire:click="toggleGroupActive({{ $group->id }})">
                        {{ $group->is_active ? 'Disable' : 'Enable' }}
                    </flux:button>
                    <flux:button variant="ghost" size="sm" wire:click="openEditGroupModal({{ $group->id }})">
                        <flux:icon.pencil class="size-4" />
                    </flux:button>
                    <flux:button variant="ghost" size="sm" wire:click="confirmDeleteGroup({{ $group->id }})">
                        <flux:icon.trash class="size-4" />
                    </flux:button>
                    <flux:button variant="primary" size="sm" wire:click="openAddRuleModal({{ $group->id }})">
                        Add Rule
                    </flux:button>
                </div>
            </div>

            @if($group->rules->isNotEmpty())
                <div class="mt-4 space-y-2">
                    @foreach($group->rules as $rule)
                        <div wire:key="rule-{{ $rule->id }}" class="flex items-center justify-between gap-4 rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
                            <div class="flex items-center gap-3">
                                <div class="flex flex-col gap-1">
                                    <flux:button variant="ghost" size="xs" wire:click="moveRuleUp({{ $rule->id }})">
                                        <flux:icon.chevron-up class="size-3" />
                                    </flux:button>
                                    <flux:button variant="ghost" size="xs" wire:click="moveRuleDown({{ $rule->id }})">
                                        <flux:icon.chevron-down class="size-3" />
                                    </flux:button>
                                </div>
                                <div>
                                    <flux:text class="font-medium">{{ $rule->name }}</flux:text>
                                </div>
                                <flux:badge size="sm" color="blue">{{ count($rule->triggers) }} {{ Str::plural('trigger', count($rule->triggers)) }}</flux:badge>
                                <flux:badge size="sm" color="purple">{{ count($rule->actions) }} {{ Str::plural('action', count($rule->actions)) }}</flux:badge>
                                @unless($rule->is_active)
                                    <flux:badge size="sm" color="yellow">Inactive</flux:badge>
                                @endunless
                                @if($rule->is_auto_apply)
                                    <flux:badge size="sm" color="green">Auto-apply</flux:badge>
                                @endif
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <flux:button variant="ghost" size="sm" wire:click="toggleRuleActive({{ $rule->id }})">
                                    {{ $rule->is_active ? 'Disable' : 'Enable' }}
                                </flux:button>
                                <flux:button variant="ghost" size="sm" wire:click="toggleRuleAutoApply({{ $rule->id }})">
                                    {{ $rule->is_auto_apply ? 'Manual' : 'Auto' }}
                                </flux:button>
                                <flux:button variant="ghost" size="sm" wire:click="openEditRuleModal({{ $rule->id }})">
                                    <flux:icon.pencil class="size-4" />
                                </flux:button>
                                <flux:button variant="ghost" size="sm" wire:click="confirmDeleteRule({{ $rule->id }})">
                                    <flux:icon.trash class="size-4" />
                                </flux:button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </flux:card>
    @empty
        <flux:card>
            <div class="flex flex-col items-center justify-center py-12 text-center">
                <flux:icon.funnel class="mb-4 size-12 text-zinc-400" />
                <flux:heading size="sm">No rule groups yet</flux:heading>
                <flux:text size="sm" class="mt-1 text-zinc-500">Create a group to start organising your transaction rules.</flux:text>
                <flux:button variant="primary" size="sm" class="mt-4" wire:click="openAddGroupModal">Add Group</flux:button>
            </div>
        </flux:card>
    @endforelse

    {{-- Group Form Modal --}}
    <flux:modal wire:model="showGroupModal" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">{{ $editingGroupId ? 'Edit Group' : 'Add Group' }}</flux:heading>

            <flux:field>
                <flux:label>Name</flux:label>
                <flux:input wire:model="groupName" placeholder="Group name" />
                <flux:error name="groupName" />
            </flux:field>

            <flux:field>
                <flux:label>Description</flux:label>
                <flux:input wire:model="groupDescription" placeholder="Optional description" />
            </flux:field>

            <flux:field>
                <flux:checkbox wire:model="groupIsActive" label="Active" />
            </flux:field>

            <flux:field>
                <flux:checkbox wire:model="groupStopProcessing" label="Stop processing after this group" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showGroupModal', false)">Cancel</flux:button>
                <flux:button variant="primary" wire:click="saveGroup">
                    {{ $editingGroupId ? 'Update' : 'Create' }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Group Delete Confirmation Modal --}}
    <flux:modal wire:model="showDeleteGroupModal" class="max-w-sm">
        <div class="space-y-4">
            <flux:heading size="lg">Delete Group</flux:heading>
            <flux:text>
                Are you sure you want to delete <strong>{{ $deletingGroupName }}</strong>?
                @if($deletingRuleCount > 0)
                    This will also delete {{ $deletingRuleCount }} {{ Str::plural('rule', $deletingRuleCount) }}.
                @endif
            </flux:text>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showDeleteGroupModal', false)">Cancel</flux:button>
                <flux:button variant="danger" wire:click="deleteGroup">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Rule Form Modal --}}
    <flux:modal wire:model="showRuleModal" class="max-w-2xl">
        <div class="space-y-4">
            <flux:heading size="lg">{{ $editingRuleId ? 'Edit Rule' : 'Add Rule' }}</flux:heading>

            <flux:field>
                <flux:label>Name</flux:label>
                <flux:input wire:model="ruleName" placeholder="Rule name" />
                <flux:error name="ruleName" />
            </flux:field>

            <flux:field>
                <flux:label>Description</flux:label>
                <flux:input wire:model="ruleDescription" placeholder="Optional description" />
            </flux:field>

            {{-- Triggers --}}
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <flux:heading size="sm">Triggers</flux:heading>
                    <flux:button variant="ghost" size="sm" wire:click="addTrigger">Add Trigger</flux:button>
                </div>
                <flux:error name="triggers" />

                @foreach($triggers as $index => $trigger)
                    <div wire:key="trigger-{{ $index }}" class="flex items-start gap-2 rounded-lg border border-neutral-200 p-2 dark:border-neutral-700">
                        <flux:field class="flex-1">
                            <flux:select wire:model="triggers.{{ $index }}.field" size="sm">
                                <flux:select.option value="">Field...</flux:select.option>
                                @foreach(RuleTriggerField::cases() as $field)
                                    <flux:select.option value="{{ $field->value }}">{{ $field->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:error name="triggers.{{ $index }}.field" />
                        </flux:field>

                        <flux:field class="flex-1">
                            <flux:select wire:model.live="triggers.{{ $index }}.operator" size="sm">
                                <flux:select.option value="">Operator...</flux:select.option>
                                @foreach(RuleTriggerOperator::cases() as $operator)
                                    <flux:select.option value="{{ $operator->value }}">{{ $operator->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:error name="triggers.{{ $index }}.operator" />
                        </flux:field>

                        @if(! isset($trigger['operator']) || $trigger['operator'] === '' || RuleTriggerOperator::tryFrom($trigger['operator'])?->requiresValue() !== false)
                            <flux:field class="flex-1">
                                <flux:input wire:model="triggers.{{ $index }}.value" size="sm" placeholder="Value" />
                                <flux:error name="triggers.{{ $index }}.value" />
                            </flux:field>
                        @endif

                        <flux:button variant="ghost" size="sm" wire:click="removeTrigger({{ $index }})">
                            <flux:icon.x-mark class="size-4" />
                        </flux:button>
                    </div>
                @endforeach
            </div>

            <flux:field>
                <flux:checkbox wire:model="ruleStrictMode" label="All conditions must match (strict mode)" />
            </flux:field>

            {{-- Actions --}}
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <flux:heading size="sm">Actions</flux:heading>
                    <flux:button variant="ghost" size="sm" wire:click="addAction">Add Action</flux:button>
                </div>
                <flux:error name="actions" />

                @foreach($actions as $index => $action)
                    <div wire:key="action-{{ $index }}" class="flex items-start gap-2 rounded-lg border border-neutral-200 p-2 dark:border-neutral-700">
                        <flux:field class="flex-1">
                            <flux:select wire:model.live="actions.{{ $index }}.type" size="sm">
                                <flux:select.option value="">Action...</flux:select.option>
                                @foreach(RuleActionType::cases() as $actionType)
                                    <flux:select.option value="{{ $actionType->value }}">{{ $actionType->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:error name="actions.{{ $index }}.type" />
                        </flux:field>

                        <flux:field class="flex-1">
                            @if(isset($action['type']) && $action['type'] === RuleActionType::SetCategory->value)
                                <flux:select wire:model="actions.{{ $index }}.value" size="sm">
                                    <flux:select.option value="">Select category...</flux:select.option>
                                    @foreach($categories as $category)
                                        <flux:select.option value="{{ $category->id }}">{{ $category->fullPath() }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @elseif(isset($action['type']) && $action['type'] === RuleActionType::LinkToPlannedTransaction->value)
                                <flux:select wire:model="actions.{{ $index }}.value" size="sm">
                                    <flux:select.option value="">Select planned transaction...</flux:select.option>
                                    @foreach($plannedTransactions as $pt)
                                        <flux:select.option value="{{ $pt->id }}">{{ $pt->description }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @else
                                <flux:input wire:model="actions.{{ $index }}.value" size="sm" placeholder="Value" />
                            @endif
                            <flux:error name="actions.{{ $index }}.value" />
                        </flux:field>

                        <flux:button variant="ghost" size="sm" wire:click="removeAction({{ $index }})">
                            <flux:icon.x-mark class="size-4" />
                        </flux:button>
                    </div>
                @endforeach
            </div>

            <flux:field>
                <flux:checkbox wire:model="ruleIsAutoApply" label="Auto-apply (execute automatically on new transactions)" />
            </flux:field>

            <flux:field>
                <flux:checkbox wire:model="ruleIsActive" label="Active" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showRuleModal', false)">Cancel</flux:button>
                <flux:button variant="primary" wire:click="saveRule">
                    {{ $editingRuleId ? 'Update' : 'Create' }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Rule Delete Confirmation Modal --}}
    <flux:modal wire:model="showDeleteRuleModal" class="max-w-sm">
        <div class="space-y-4">
            <flux:heading size="lg">Delete Rule</flux:heading>
            <flux:text>Are you sure you want to delete <strong>{{ $deletingRuleName }}</strong>?</flux:text>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showDeleteRuleModal', false)">Cancel</flux:button>
                <flux:button variant="danger" wire:click="deleteRule">Delete</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
