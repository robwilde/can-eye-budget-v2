@php
    use App\Enums\RuleActionType;
    use App\Enums\RuleTriggerField;
    use App\Enums\RuleTriggerOperator;
    use App\Enums\TransactionDirection;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;
@endphp
<div class="space-y-6">
    <div class="flex flex-col gap-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="max-w-2xl">
                <flux:heading size="lg">Rules</flux:heading>
                <flux:text size="sm" class="mt-1 text-zinc-500">Auto-apply rules run after every import and when you click Run rules. Other rules turn their matches into suggestions for you to accept.</flux:text>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <flux:button size="sm" icon="play" wire:click="runRules" :disabled="! $hasActiveRules">Run rules now</flux:button>
                <flux:button variant="primary" size="sm" wire:click="openAddGroupModal">Add Group</flux:button>
            </div>
        </div>
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search rules..." size="sm" class="w-full sm:max-w-sm" />
    </div>

    @php($searching = mb_trim($search) !== '')

    @forelse($groups as $group)
        <x-cib.card wire:key="group-{{ $group->id }}">
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
                <div class="flex min-w-0 items-center gap-3">
                    @unless($searching)
                        <div class="flex shrink-0 flex-col items-center">
                            <flux:button variant="ghost" size="xs" wire:click="moveGroupUp({{ $group->id }})">
                                <flux:icon.chevron-up class="size-3" />
                            </flux:button>
                            <flux:button variant="ghost" size="xs" wire:click="moveGroupDown({{ $group->id }})">
                                <flux:icon.chevron-down class="size-3" />
                            </flux:button>
                        </div>
                    @endunless
                    <div class="min-w-0 space-y-1">
                        <x-cib.sec-head :title="$group->name" class="mb-0 flex-wrap justify-start gap-x-3 gap-y-2">
                            <x-cib.stat-pill tone="neutral" :value="$group->rules->count().' '.Str::plural('rule', $group->rules->count())" />
                            @unless($group->is_active)<x-cib.stat-pill tone="planned">Inactive</x-cib.stat-pill>@endunless
                            @if($group->stop_processing)<x-cib.stat-pill tone="posted">Stop Processing</x-cib.stat-pill>@endif
                        </x-cib.sec-head>
                        @if($group->description)
                            <flux:text size="sm" class="text-zinc-500">{{ $group->description }}</flux:text>
                        @endif
                    </div>
                </div>

                <div class="flex shrink-0 flex-wrap items-center gap-1">
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
                <div class="mt-5 space-y-2">
                    @foreach($group->rules as $rule)
                        <div wire:key="rule-{{ $rule->id }}" class="flex flex-col gap-3 rounded-lg border border-neutral-200 px-4 py-3 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex min-w-0 flex-1 items-center gap-3">
                                @unless($searching)
                                    <div class="flex shrink-0 flex-col items-center">
                                        <flux:button variant="ghost" size="xs" wire:click="moveRuleUp({{ $rule->id }})">
                                            <flux:icon.chevron-up class="size-3" />
                                        </flux:button>
                                        <flux:button variant="ghost" size="xs" wire:click="moveRuleDown({{ $rule->id }})">
                                            <flux:icon.chevron-down class="size-3" />
                                        </flux:button>
                                    </div>
                                @endunless
                                <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1.5">
                                    <div class="min-w-0">
                                        <flux:text class="font-medium">{{ $rule->name }}</flux:text>
                                        @if($rule->description)
                                            <flux:text size="sm" class="text-zinc-500">{{ $rule->description }}</flux:text>
                                        @endif
                                    </div>
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <flux:badge size="sm" color="blue">{{ count($rule->triggers) }} {{ Str::plural('trigger', count($rule->triggers)) }}</flux:badge>
                                        <flux:badge size="sm" color="purple">{{ count($rule->actions) }} {{ Str::plural('action', count($rule->actions)) }}</flux:badge>
                                        @unless($rule->is_active)
                                            <flux:badge size="sm" color="yellow">Inactive</flux:badge>
                                        @endunless
                                    </div>
                                </div>
                            </div>

                            <div class="flex shrink-0 flex-wrap items-center gap-1 lg:justify-end">
                                <flux:button variant="ghost" size="sm" wire:click="toggleRuleActive({{ $rule->id }})">
                                    {{ $rule->is_active ? 'Disable' : 'Enable' }}
                                </flux:button>
                                <div class="px-2">
                                    <flux:switch label="Auto-apply" :checked="$rule->is_auto_apply" wire:click="toggleRuleAutoApply({{ $rule->id }})" />
                                </div>
                                <flux:button variant="ghost" size="sm" wire:click="applyRule({{ $rule->id }})" :disabled="! $rule->is_active">
                                    Apply now
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
        </x-cib.card>
    @empty
        @if($searching)
            <x-cib.card>
                <x-cib.empty-state icon="magnifying-glass" :title="'No rules match “'.mb_trim($search).'”'" description="Try a different name or description."><x-slot:action><flux:button size="sm" wire:click="$set('search', '')">Clear search</flux:button></x-slot:action></x-cib.empty-state>
            </x-cib.card>
        @else
            <x-cib.card>
                <x-cib.empty-state icon="funnel" title="No rule groups yet" description="Create a group to start organising your transaction rules."><x-slot:action><flux:button variant="primary" size="sm" wire:click="openAddGroupModal">Add Group</flux:button></x-slot:action></x-cib.empty-state>
            </x-cib.card>
        @endif
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
                                <x-category-combobox
                                    wire:model="actions.{{ $index }}.value"
                                    :categories="$categories"
                                    placeholder="Select category..."
                                    size="sm"
                                />
                            @elseif(isset($action['type']) && $action['type'] === RuleActionType::LinkToPlannedTransaction->value)
                                <flux:select wire:model="actions.{{ $index }}.value" size="sm">
                                    <flux:select.option value="">Select planned transaction...</flux:select.option>
                                    @foreach($plannedTransactions as $pt)
                                        <flux:select.option value="{{ $pt->id }}">{{ $pt->description }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @elseif(isset($action['type']) && $action['type'] === RuleActionType::FoldIntoParent->value)
                                <flux:text size="sm" class="mt-2">Finds the matching parent purchase (same day, same account, matching reference) and folds this fee into it.</flux:text>
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

            @if($ruleTestResult)
                @php($testSections = [
                    ['key' => 'not-yet', 'title' => 'Not yet applied', 'hint' => 'Apply now would change these.', 'color' => 'amber', 'count' => $ruleTestResult->notYetAppliedCount, 'rows' => $ruleTestResult->notYetApplied],
                    ['key' => 'skipped', 'title' => 'Skipped', 'hint' => 'Apply now leaves these alone.', 'color' => 'zinc', 'count' => $ruleTestResult->skippedCount, 'rows' => $ruleTestResult->skipped],
                    ['key' => 'already', 'title' => 'Already applied', 'hint' => 'The actions would change nothing here.', 'color' => 'green', 'count' => $ruleTestResult->alreadyAppliedCount, 'rows' => $ruleTestResult->alreadyApplied],
                ])
                <div wire:dirty.remove wire:target="triggers,actions,ruleStrictMode" class="w-full space-y-3 rounded-lg border border-neutral-200 p-3 dark:border-neutral-700" data-testid="rule-test-result">
                    <flux:text data-testid="rule-test-total">
                        Matches <strong>{{ $ruleTestResult->totalMatches() }}</strong>
                        {{ Str::plural('transaction', $ruleTestResult->totalMatches()) }}.
                    </flux:text>

                    @if($ruleTestResult->appendsNotes)
                        <flux:text size="sm" class="text-amber-700 dark:text-amber-500" data-testid="rule-test-appends-notes">
                            Apply now will add the note again each time it runs, so these rows never show as already applied.
                        </flux:text>
                    @endif

                    @foreach($testSections as $section)
                        @if($section['count'] > 0)
                            <div class="space-y-1" data-testid="rule-test-{{ $section['key'] }}">
                                <div class="flex items-center gap-2">
                                    <flux:heading size="sm">{{ $section['title'] }}</flux:heading>
                                    <flux:badge size="sm" :color="$section['color']" data-testid="rule-test-{{ $section['key'] }}-count">{{ $section['count'] }}</flux:badge>
                                </div>
                                <flux:text size="sm">{{ $section['hint'] }}</flux:text>
                                @if($section['count'] > count($section['rows']))
                                    <flux:text size="sm" data-testid="rule-test-{{ $section['key'] }}-capped">Showing {{ count($section['rows']) }} of {{ $section['count'] }}</flux:text>
                                @endif
                                <div class="max-h-64 overflow-y-auto">
                                    @foreach($section['rows'] as $row)
                                        <x-cib.tx-row
                                            wire:key="rule-test-{{ $section['key'] }}-{{ $row->id }}"
                                            :transaction-id="null"
                                            :planned-transaction-id="null"
                                            :name="$row->name"
                                            :amount="$row->amount"
                                            :tone="$row->direction === TransactionDirection::Debit ? 'out' : 'inc'"
                                        >
                                            <x-slot:meta>
                                                {{ Carbon::parse($row->postDate)->format('j M Y') }} · {{ $row->accountName }}
                                                @if($row->categoryKept)
                                                    <flux:badge size="sm" color="amber" class="ml-1">Category kept — categorised by hand</flux:badge>
                                                @endif
                                                @if($row->skipReason)
                                                    <flux:badge size="sm" color="zinc" class="ml-1">{{ $row->skipReason->label() }}</flux:badge>
                                                @endif
                                            </x-slot:meta>
                                            <x-slot:category>{{ $row->categoryPath ?? 'Uncategorised' }}</x-slot:category>
                                        </x-cib.tx-row>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showRuleModal', false)">Cancel</flux:button>
                <flux:button variant="filled" wire:click="testRule" data-testid="rule-test">Test</flux:button>
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
