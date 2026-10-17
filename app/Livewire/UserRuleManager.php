<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\RuleTestResult;
use App\Enums\PipelineTrigger;
use App\Enums\RuleActionType;
use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Jobs\RunTransactionAnalysisJob;
use App\Livewire\Attributes\NotAudited;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Services\RuleTester;
use App\Services\UserRuleApplier;
use Closure;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

final class UserRuleManager extends Component
{
    #[Locked]
    public ?RuleTestResult $ruleTestResult = null;

    #[Url(except: '')]
    public string $search = '';

    public bool $showGroupModal = false;

    public ?int $editingGroupId = null;

    public string $groupName = '';

    public string $groupDescription = '';

    public bool $groupIsActive = true;

    public bool $groupStopProcessing = false;

    public bool $showDeleteGroupModal = false;

    public ?int $deletingGroupId = null;

    public string $deletingGroupName = '';

    public int $deletingRuleCount = 0;

    public bool $showRuleModal = false;

    public ?int $editingRuleId = null;

    public ?int $ruleGroupId = null;

    public string $ruleName = '';

    public string $ruleDescription = '';

    /** @var array<int, array<string, mixed>> */
    public array $triggers = [];

    /** @var array<int, array<string, string>> */
    public array $actions = [];

    public bool $ruleStrictMode = true;

    public bool $ruleIsAutoApply = false;

    public bool $ruleIsActive = true;

    public bool $showDeleteRuleModal = false;

    public ?int $deletingRuleId = null;

    public string $deletingRuleName = '';

    // ─── Group Modal ─────────────────────────────────────

    public function openAddGroupModal(): void
    {
        $this->resetGroupForm();
        $this->showGroupModal = true;
    }

    public function openEditGroupModal(int $groupId): void
    {
        $group = UserRuleGroup::where('id', $groupId)
            ->where('user_id', auth()->id())
            ->first();

        if (! $group) {
            return;
        }

        $this->editingGroupId = $group->id;
        $this->groupName = $group->name;
        $this->groupDescription = $group->description ?? '';
        $this->groupIsActive = $group->is_active;
        $this->groupStopProcessing = $group->stop_processing;
        $this->showGroupModal = true;
    }

    public function saveGroup(): void
    {
        $this->validate([
            'groupName' => ['required', 'string', 'max:255'],
        ]);

        if ($this->editingGroupId) {
            $group = UserRuleGroup::where('id', $this->editingGroupId)
                ->where('user_id', auth()->id())
                ->first();

            if ($group) {
                $group->update([
                    'name' => $this->groupName,
                    'description' => $this->groupDescription ?: null,
                    'is_active' => $this->groupIsActive,
                    'stop_processing' => $this->groupStopProcessing,
                ]);

                Flux::toast(text: 'Group updated', variant: 'success');
            }
        } else {
            $maxOrder = UserRuleGroup::where('user_id', auth()->id())->max('order') ?? -1;

            UserRuleGroup::create([
                'user_id' => auth()->id(),
                'name' => $this->groupName,
                'description' => $this->groupDescription ?: null,
                'is_active' => $this->groupIsActive,
                'stop_processing' => $this->groupStopProcessing,
                'order' => $maxOrder + 1,
            ]);

            Flux::toast(text: 'Group created', variant: 'success');
        }

        $this->showGroupModal = false;
        $this->resetGroupForm();
    }

    public function confirmDeleteGroup(int $groupId): void
    {
        $group = UserRuleGroup::where('id', $groupId)
            ->where('user_id', auth()->id())
            ->first();

        if (! $group) {
            return;
        }

        $this->deletingGroupId = $group->id;
        $this->deletingGroupName = $group->name;
        $this->deletingRuleCount = $group->rules()->count();
        $this->showDeleteGroupModal = true;
    }

    public function deleteGroup(): void
    {
        if (! $this->deletingGroupId) {
            return;
        }

        $group = UserRuleGroup::where('id', $this->deletingGroupId)
            ->where('user_id', auth()->id())
            ->first();

        if ($group) {
            $group->rules()->delete();
            $group->delete();

            Flux::toast(text: 'Group deleted', variant: 'success');
        }

        $this->showDeleteGroupModal = false;
        $this->deletingGroupId = null;
    }

    public function toggleGroupActive(int $groupId): void
    {
        $group = UserRuleGroup::where('id', $groupId)
            ->where('user_id', auth()->id())
            ->first();

        $group?->update(['is_active' => ! $group->is_active]);
    }

    public function toggleGroupStopProcessing(int $groupId): void
    {
        $group = UserRuleGroup::where('id', $groupId)
            ->where('user_id', auth()->id())
            ->first();

        $group?->update(['stop_processing' => ! $group->stop_processing]);
    }

    // ─── Rule Modal ──────────────────────────────────────

    public function openAddRuleModal(int $groupId): void
    {
        $group = UserRuleGroup::where('id', $groupId)
            ->where('user_id', auth()->id())
            ->first();

        if (! $group) {
            return;
        }

        $this->resetRuleForm();
        $this->ruleGroupId = $group->id;
        $this->triggers = [['field' => '', 'operator' => '', 'value' => '']];
        $this->actions = [['type' => '', 'value' => '']];
        $this->showRuleModal = true;
    }

    public function openEditRuleModal(int $ruleId): void
    {
        $rule = UserRule::where('id', $ruleId)
            ->where('user_id', auth()->id())
            ->first();

        if (! $rule) {
            return;
        }

        $this->resetRuleForm();
        $this->editingRuleId = $rule->id;
        $this->ruleGroupId = $rule->user_rule_group_id;
        $this->ruleName = $rule->name;
        $this->ruleDescription = $rule->description ?? '';
        $this->triggers = $rule->triggers;
        $this->actions = $rule->actions;
        $this->ruleStrictMode = $rule->strict_mode;
        $this->ruleIsAutoApply = $rule->is_auto_apply;
        $this->ruleIsActive = $rule->is_active;
        $this->showRuleModal = true;
    }

    public function saveRule(): void
    {
        $this->validate($this->ruleFormRules());

        if ($this->editingRuleId) {
            $rule = UserRule::where('id', $this->editingRuleId)
                ->where('user_id', auth()->id())
                ->first();

            if ($rule) {
                $rule->update([
                    'name' => $this->ruleName,
                    'description' => $this->ruleDescription ?: null,
                    'triggers' => $this->triggers,
                    'actions' => $this->actions,
                    'strict_mode' => $this->ruleStrictMode,
                    'is_auto_apply' => $this->ruleIsAutoApply,
                    'is_active' => $this->ruleIsActive,
                ]);

                Flux::toast(text: 'Rule updated', variant: 'success');
            }
        } else {
            $group = UserRuleGroup::where('id', $this->ruleGroupId)
                ->where('user_id', auth()->id())
                ->first();

            if (! $group) {
                return;
            }

            $maxOrder = UserRule::where('user_rule_group_id', $group->id)->max('order') ?? -1;

            UserRule::create([
                'user_id' => auth()->id(),
                'user_rule_group_id' => $group->id,
                'name' => $this->ruleName,
                'description' => $this->ruleDescription ?: null,
                'triggers' => $this->triggers,
                'actions' => $this->actions,
                'strict_mode' => $this->ruleStrictMode,
                'is_auto_apply' => $this->ruleIsAutoApply,
                'is_active' => $this->ruleIsActive,
                'order' => $maxOrder + 1,
            ]);

            Flux::toast(text: 'Rule created', variant: 'success');
        }

        $this->showRuleModal = false;
        $this->resetRuleForm();
    }

    public function addTrigger(): void
    {
        $this->triggers[] = ['field' => '', 'operator' => '', 'value' => ''];
        $this->ruleTestResult = null;
    }

    public function removeTrigger(int $index): void
    {
        unset($this->triggers[$index]);
        $this->triggers = array_values($this->triggers);
        $this->ruleTestResult = null;
    }

    public function addAction(): void
    {
        $this->actions[] = ['type' => '', 'value' => ''];
        $this->ruleTestResult = null;
    }

    public function removeAction(int $index): void
    {
        unset($this->actions[$index]);
        $this->actions = array_values($this->actions);
        $this->ruleTestResult = null;
    }

    #[NotAudited]
    public function testRule(RuleTester $tester): void
    {
        $this->ruleTestResult = null;

        $this->validate($this->draftRuleRules());

        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        $this->ruleTestResult = $tester->test($user->id, $this->triggers, $this->ruleStrictMode, $this->actions);
    }

    public function updated(string $property): void
    {
        if (in_array(Str::before($property, '.'), ['triggers', 'actions', 'ruleStrictMode', 'showRuleModal'], true)) {
            $this->ruleTestResult = null;
        }
    }

    public function confirmDeleteRule(int $ruleId): void
    {
        $rule = UserRule::where('id', $ruleId)
            ->where('user_id', auth()->id())
            ->first();

        if (! $rule) {
            return;
        }

        $this->deletingRuleId = $rule->id;
        $this->deletingRuleName = $rule->name;
        $this->showDeleteRuleModal = true;
    }

    public function deleteRule(): void
    {
        if (! $this->deletingRuleId) {
            return;
        }

        $rule = UserRule::where('id', $this->deletingRuleId)
            ->where('user_id', auth()->id())
            ->first();

        if ($rule) {
            $rule->delete();

            Flux::toast(text: 'Rule deleted', variant: 'success');
        }

        $this->showDeleteRuleModal = false;
        $this->deletingRuleId = null;
    }

    public function toggleRuleActive(int $ruleId): void
    {
        $rule = UserRule::where('id', $ruleId)
            ->where('user_id', auth()->id())
            ->first();

        $rule?->update(['is_active' => ! $rule->is_active]);
    }

    public function toggleRuleAutoApply(int $ruleId): void
    {
        $rule = UserRule::where('id', $ruleId)
            ->where('user_id', auth()->id())
            ->first();

        $rule?->update(['is_auto_apply' => ! $rule->is_auto_apply]);
    }

    // ─── Run ─────────────────────────────────────────────

    public function runRules(): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        // The job is unique per user for 300 s, so a repeat click while one is
        // queued is dropped by the queue; the message stays true either way.
        RunTransactionAnalysisJob::dispatch($user, PipelineTrigger::Manual);

        Flux::toast(text: 'Rules queued — results will show in Suggestions shortly', variant: 'success');
    }

    public function applyRule(int $ruleId, UserRuleApplier $applier): void
    {
        $rule = UserRule::where('id', $ruleId)
            ->where('user_id', auth()->id())
            ->first();

        if (! $rule || ! $rule->is_active) {
            return;
        }

        $count = $applier->applyToHistory($rule);

        Flux::toast(text: "Applied to {$count} transactions", variant: 'success');
    }

    // ─── Reorder ─────────────────────────────────────────

    public function moveGroupUp(int $groupId): void
    {
        $group = UserRuleGroup::where('id', $groupId)
            ->where('user_id', auth()->id())
            ->first();

        if (! $group) {
            return;
        }

        $above = UserRuleGroup::where('user_id', auth()->id())
            ->where('order', '<', $group->order)
            ->orderByDesc('order')
            ->first();

        if ($above) {
            $this->swapOrder($group, $above);
        }
    }

    public function moveGroupDown(int $groupId): void
    {
        $group = UserRuleGroup::where('id', $groupId)
            ->where('user_id', auth()->id())
            ->first();

        if (! $group) {
            return;
        }

        $below = UserRuleGroup::where('user_id', auth()->id())
            ->where('order', '>', $group->order)
            ->orderBy('order')
            ->first();

        if ($below) {
            $this->swapOrder($group, $below);
        }
    }

    public function moveRuleUp(int $ruleId): void
    {
        $rule = UserRule::where('id', $ruleId)
            ->where('user_id', auth()->id())
            ->first();

        if (! $rule) {
            return;
        }

        $above = UserRule::where('user_rule_group_id', $rule->user_rule_group_id)
            ->where('order', '<', $rule->order)
            ->orderByDesc('order')
            ->first();

        if ($above) {
            $this->swapOrder($rule, $above);
        }
    }

    public function moveRuleDown(int $ruleId): void
    {
        $rule = UserRule::where('id', $ruleId)
            ->where('user_id', auth()->id())
            ->first();

        if (! $rule) {
            return;
        }

        $below = UserRule::where('user_rule_group_id', $rule->user_rule_group_id)
            ->where('order', '>', $rule->order)
            ->orderBy('order')
            ->first();

        if ($below) {
            $this->swapOrder($rule, $below);
        }
    }

    // ─── Render ──────────────────────────────────────────

    public function render(): View
    {
        $matchesSearch = $this->searchConstraint();

        return view('livewire.user-rule-manager', [
            'hasActiveRules' => UserRule::where('user_id', auth()->id())
                ->active()
                ->whereHas('group', fn (Builder $q) => $q->where('is_active', true))
                ->exists(),
            'groups' => UserRuleGroup::where('user_id', auth()->id())
                ->ordered()
                ->when($matchesSearch !== null, fn (Builder $q) => $q->whereHas('rules', $matchesSearch))
                ->with(['rules' => fn ($q) => $q->when($matchesSearch !== null, $matchesSearch)->ordered()])
                ->get(),
            'categories' => Category::visibleSortedByFullPath(),
            'plannedTransactions' => PlannedTransaction::where('user_id', auth()->id())
                ->where('is_active', true)
                ->orderBy('description')
                ->get(),
        ]);
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return ['triggers.*.value.required' => 'The value field is required for the selected operator.'];
    }

    // ─── Private ─────────────────────────────────────────

    private function searchConstraint(): ?Closure
    {
        $term = mb_trim($this->search);

        if ($term === '') {
            return null;
        }

        return static fn (Builder $q) => $q->where(fn (Builder $q) => $q
            ->where('name', 'like', "%{$term}%")
            ->orWhere('description', 'like', "%{$term}%"));
    }

    private function resetGroupForm(): void
    {
        $this->editingGroupId = null;
        $this->groupName = '';
        $this->groupDescription = '';
        $this->groupIsActive = true;
        $this->groupStopProcessing = false;
    }

    private function resetRuleForm(): void
    {
        $this->editingRuleId = null;
        $this->ruleGroupId = null;
        $this->ruleName = '';
        $this->ruleDescription = '';
        $this->triggers = [];
        $this->actions = [];
        $this->ruleStrictMode = true;
        $this->ruleIsAutoApply = false;
        $this->ruleIsActive = true;
        $this->ruleTestResult = null;
    }

    /** @return array<string, array<int, mixed>> */
    private function ruleFormRules(): array
    {
        return ['ruleName' => ['required', 'string', 'max:255'], ...$this->draftRuleRules()];
    }

    /** @return array<string, array<int, mixed>> */
    private function draftRuleRules(): array
    {
        $rules = [
            'triggers' => ['required', 'array', 'min:1'],
            'triggers.*.field' => ['required', Rule::in(array_column(RuleTriggerField::cases(), 'value'))],
            'triggers.*.operator' => ['required', Rule::in(array_column(RuleTriggerOperator::cases(), 'value'))],
            'actions' => ['required', 'array', 'min:1'],
            'actions.*.type' => ['required', Rule::in(array_column(RuleActionType::cases(), 'value'))],
        ];

        foreach (array_keys($this->triggers) as $index) {
            $operatorValue = $this->triggers[$index]['operator'] ?? '';
            $operator = is_string($operatorValue) ? RuleTriggerOperator::tryFrom($operatorValue) : null;

            $rules["triggers.{$index}.value"] = $operator?->requiresValue()
                ? ['required', 'string']
                : ['nullable', 'string'];
        }

        // Build the value rule per action index: value-requiring types must have
        // a non-empty value (required is implicit so it catches empty strings),
        // while valueless actions (e.g. fold into parent) accept an empty value.
        // A clean description is written to a 255-character column.
        foreach (array_keys($this->actions) as $index) {
            $type = RuleActionType::tryFrom($this->actions[$index]['type'] ?? '');

            $rules["actions.{$index}.value"] = match (true) {
                $type !== null && ! $type->requiresValue() => ['nullable', 'string'],
                $type === RuleActionType::SetCleanDescription => ['required', 'string', 'max:255'],
                default => ['required', 'string'],
            };
        }

        return $rules;
    }

    private function swapOrder(UserRuleGroup|UserRule $a, UserRuleGroup|UserRule $b): void
    {
        $tempOrder = $a->order;
        $a->update(['order' => $b->order]);
        $b->update(['order' => $tempOrder]);
    }
}
