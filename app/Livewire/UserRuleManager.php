<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\RuleActionType;
use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use Closure;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

final class UserRuleManager extends Component
{
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

    /** @var array<int, array<string, string>> */
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
    }

    public function removeTrigger(int $index): void
    {
        unset($this->triggers[$index]);
        $this->triggers = array_values($this->triggers);
    }

    public function addAction(): void
    {
        $this->actions[] = ['type' => '', 'value' => ''];
    }

    public function removeAction(int $index): void
    {
        unset($this->actions[$index]);
        $this->actions = array_values($this->actions);
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
        return view('livewire.user-rule-manager', [
            'groups' => UserRuleGroup::where('user_id', auth()->id())
                ->ordered()
                ->with(['rules' => fn ($q) => $q->ordered()])
                ->get(),
            'categories' => Category::visibleSortedByFullPath(),
            'plannedTransactions' => PlannedTransaction::where('user_id', auth()->id())
                ->where('is_active', true)
                ->orderBy('description')
                ->get(),
        ]);
    }

    // ─── Private ─────────────────────────────────────────

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
    }

    /** @return array<string, array<int, mixed>> */
    private function ruleFormRules(): array
    {
        return [
            'ruleName' => ['required', 'string', 'max:255'],
            'triggers' => ['required', 'array', 'min:1'],
            'triggers.*.field' => ['required', Rule::in(array_column(RuleTriggerField::cases(), 'value'))],
            'triggers.*.operator' => ['required', Rule::in(array_column(RuleTriggerOperator::cases(), 'value'))],
            'triggers.*.value' => $this->triggerValueRules(),
            'actions' => ['required', 'array', 'min:1'],
            'actions.*.type' => ['required', Rule::in(array_column(RuleActionType::cases(), 'value'))],
            'actions.*.value' => ['required', 'string'],
        ];
    }

    /** @return array<int, mixed> */
    private function triggerValueRules(): array
    {
        return [
            'nullable',
            'string',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! preg_match('/^triggers\.(\d+)\.value$/', $attribute, $matches)) {
                    return;
                }

                $triggerIndex = (int) $matches[1];
                $operatorValue = $this->triggers[$triggerIndex]['operator'] ?? null;

                if (! is_string($operatorValue)) {
                    return;
                }

                $operator = RuleTriggerOperator::tryFrom($operatorValue);

                if ($operator === null || ! $operator->requiresValue()) {
                    return;
                }

                if ($value === null || (is_string($value) && mb_trim($value) === '')) {
                    $fail('The value field is required for the selected operator.');
                }
            },
        ];
    }

    private function swapOrder(UserRuleGroup|UserRule $a, UserRuleGroup|UserRule $b): void
    {
        $tempOrder = $a->order;
        $a->update(['order' => $b->order]);
        $b->update(['order' => $tempOrder]);
    }
}
