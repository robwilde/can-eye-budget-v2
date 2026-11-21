<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\RuleTestResult;
use App\Enums\PipelineTrigger;
use App\Enums\RuleActionType;
use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Exceptions\RuleBaselineTimedOutException;
use App\Exceptions\RuleImportException;
use App\Jobs\ApplyUserRulesJob;
use App\Jobs\RunTransactionAnalysisJob;
use App\Livewire\Attributes\NotAudited;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Services\RuleApplyPreview;
use App\Services\RuleDefinitionExporter;
use App\Services\RuleDefinitionImporter;
use App\Services\RuleEmailBaseline;
use App\Services\RuleFormRules;
use App\Services\RuleRunHistory;
use App\Services\RuleTester;
use App\Support\Rules\RuleAiCategorisation;
use App\Support\Rules\RuleTriggers;
use Closure;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class UserRuleManager extends Component
{
    use WithFileUploads;

    private const string BASELINE_WARNING = 'This rule is still off: recording the transactions it already matches took too long. Try switching it on again in a moment.';

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

    /** @var array<int, array<string, mixed>> */
    public array $actions = [];

    public bool $ruleStrictMode = true;

    public bool $ruleIsAutoApply = false;

    public bool $ruleIsActive = true;

    public string $ruleEffectiveFrom = '';

    public bool $showDeleteAllRulesModal = false;

    public ?string $ruleWarning = null;

    public bool $showImportModal = false;

    #[Validate('nullable|file|extensions:json,txt|max:1024')]
    public ?TemporaryUploadedFile $importFile = null;

    /** @var array{groups_created: int, rules_created: int, rules_updated: int, skipped: list<string>}|null */
    #[Locked]
    public ?array $importSummary = null;

    /** @var list<string> */
    #[Locked]
    public array $importErrors = [];

    public bool $showApplyModal = false;

    #[Locked]
    public ?int $applyingRuleId = null;

    /** @var array{rule: string|null, matches: int, would_change: int, rules_first: int, suggest_only_first: int, transactions: int, rules: int, skipped: list<string>}|null */
    #[Locked]
    public ?array $applyPreview = null;

    public bool $showDeleteRuleModal = false;

    public ?int $deletingRuleId = null;

    public string $deletingRuleName = '';

    /**
     * The transaction modal's "Create rule" prompt links here with the group, match value and
     * category in the query string. Nothing is written; the form opens prefilled.
     */
    public function mount(): void
    {
        $match = request()->query('rule_match');
        $groupId = request()->query('rule_group');
        $categoryId = request()->query('rule_category');

        if (! is_string($match) || mb_trim($match) === '' || ! is_string($groupId) || ! ctype_digit($groupId) || ! is_string($categoryId) || ! ctype_digit($categoryId)) {
            return;
        }

        $visible = Category::visible()->where('user_id', auth()->id())->whereKey((int) $categoryId)->exists();

        if (! $visible) {
            return;
        }

        $this->openAddRuleModal((int) $groupId);

        if (! $this->showRuleModal) {
            return;
        }

        $this->triggers = [[
            'field' => RuleTriggerField::Description->value,
            'operator' => RuleTriggerOperator::Contains->value,
            'value' => mb_substr(mb_trim($match), 0, 255),
        ]];
        $this->actions = [['type' => RuleActionType::SetCategory->value, 'value' => $categoryId]];
    }

    // ─── Group Modal ─────────────────────────────────────

    public function exportRules(RuleDefinitionExporter $exporter): StreamedResponse
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return response()->streamDownload(
            static function () use ($exporter, $user): void {
                echo $exporter->toJson($user);
            },
            'rules-'.now()->format('Y-m-d').'.json',
            ['Content-Type' => 'application/json'],
        );
    }

    public function openImportModal(): void
    {
        $this->resetImport();
        $this->showImportModal = true;
    }

    public function importRules(RuleDefinitionImporter $importer): void
    {
        $this->importSummary = null;
        $this->importErrors = [];

        $this->validate(['importFile' => ['required', 'file', 'extensions:json,txt', 'max:1024']]);

        $user = auth()->user();

        abort_unless($user instanceof User && $this->importFile instanceof TemporaryUploadedFile, 403);

        try {
            $result = $importer->import($user, (string) $this->importFile->get());
        } catch (RuleImportException $exception) {
            $this->importErrors = $exception->errors;

            return;
        }

        $this->importFile = null;
        $this->importSummary = [
            'groups_created' => $result->groupsCreated,
            'rules_created' => $result->rulesCreated,
            'rules_updated' => $result->rulesUpdated,
            'skipped' => $result->skipped,
        ];
    }

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

        if (! $group) {
            return;
        }

        try {
            DB::transaction(function () use ($group): void {
                $locked = UserRuleGroup::where('id', $group->id)->where('user_id', auth()->id())->lockForUpdate()->firstOrFail();
                $locked->update(['is_active' => ! $locked->is_active]);

                if ($locked->is_active) {
                    $locked->rules()->where('is_active', true)->orderBy('id')->lockForUpdate()->get()->each(fn (UserRule $rule) => $this->recordBaseline($rule));
                }
            });

            $this->ruleWarning = null;
        } catch (RuleBaselineTimedOutException) {
            $this->ruleWarning = self::BASELINE_WARNING;
        }
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
        $this->ruleEffectiveFrom = $rule->effective_from?->toDateString() ?? '';
        $this->showRuleModal = true;
    }

    public function saveRule(): void
    {
        $this->validate($this->ruleFormRules());

        try {
            $outcome = DB::transaction(fn (): ?string => $this->persistRule(forceInactive: false));
            $this->ruleWarning = null;
        } catch (RuleBaselineTimedOutException) {
            $outcome = DB::transaction(fn (): ?string => $this->persistRule(forceInactive: true));
            $this->ruleWarning = self::BASELINE_WARNING;
        }

        if ($outcome === null) {
            return;
        }

        Flux::toast(text: $outcome === 'created' ? 'Rule created' : 'Rule updated', variant: 'success');

        $this->showRuleModal = false;
        $this->resetRuleForm();
    }

    public function addTrigger(): void
    {
        $this->triggers[] = ['field' => '', 'operator' => '', 'value' => ''];
        $this->ruleTestResult = null;
    }

    public function addGroup(): void
    {
        if (array_any($this->triggers, RuleTriggers::isGroup(...))) {
            return;
        }

        $this->triggers[] = ['type' => RuleTriggers::GROUP, 'match' => RuleTriggers::MATCH_ANY, 'conditions' => [['field' => '', 'operator' => '', 'value' => '']]];
        $this->ruleTestResult = null;
    }

    public function addGroupCondition(int $index): void
    {
        if (! RuleTriggers::isGroup($this->triggers[$index] ?? null)) {
            return;
        }

        $this->triggers[$index]['conditions'][] = ['field' => '', 'operator' => '', 'value' => ''];
        $this->ruleTestResult = null;
    }

    public function removeGroupCondition(int $index, int $conditionIndex): void
    {
        if (! RuleTriggers::isGroup($this->triggers[$index] ?? null)) {
            return;
        }

        unset($this->triggers[$index]['conditions'][$conditionIndex]);
        $this->triggers[$index]['conditions'] = array_values($this->triggers[$index]['conditions']);
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

        $this->ruleTestResult = $tester->test(
            $user->id,
            $this->triggers,
            $this->ruleStrictMode,
            $this->actions,
            $this->ruleEffectiveFrom ?: null,
            overrideLocks: true,
        );
    }

    public function updated(string $property): void
    {
        if (in_array(Str::before($property, '.'), ['triggers', 'actions', 'ruleStrictMode', 'ruleEffectiveFrom', 'showRuleModal'], true)) {
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

    public function confirmDeleteAllRules(): void
    {
        $this->showDeleteAllRulesModal = true;
    }

    public function deleteAllRules(): void
    {
        if (! $this->showDeleteAllRulesModal) {
            return;
        }

        $deleted = DB::transaction(fn (): int => UserRule::where('user_id', auth()->id())->delete());

        $this->showDeleteAllRulesModal = false;

        Flux::toast(text: "Deleted {$deleted} ".Str::plural('rule', $deleted), variant: 'success');
    }

    public function toggleRuleActive(int $ruleId): void
    {
        $rule = UserRule::where('id', $ruleId)
            ->where('user_id', auth()->id())
            ->first();

        if (! $rule) {
            return;
        }

        try {
            DB::transaction(function () use ($rule): void {
                $locked = UserRule::where('id', $rule->id)->where('user_id', auth()->id())->lockForUpdate()->firstOrFail();
                $locked->update(['is_active' => ! $locked->is_active]);
                $this->recordBaseline($locked);
            });

            $this->ruleWarning = null;
        } catch (RuleBaselineTimedOutException) {
            $this->ruleWarning = self::BASELINE_WARNING;
        }
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

    /**
     * Opens the apply confirmation for one rule with the counts it would work on. Counting only
     * reads, so it is not audited; applyRule() is what is audited.
     */
    #[NotAudited]
    public function confirmApplyRule(int $ruleId, RuleApplyPreview $preview): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        $rule = UserRule::where('id', $ruleId)->where('user_id', $user->id)->first();

        if (! $rule) {
            return;
        }

        $this->applyingRuleId = $rule->id;
        $this->applyPreview = [
            'rule' => $rule->name,
            'transactions' => 0,
            'rules' => 0,
            'skipped' => [],
            ...$preview->forRule($user, $rule),
        ];
        $this->showApplyModal = true;
    }

    /**
     * Opens the "Apply all" confirmation with the number of different transactions the rules
     * would work on.
     */
    #[NotAudited]
    public function confirmApplyAll(RuleApplyPreview $preview): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        $this->applyingRuleId = null;
        $this->applyPreview = [
            'rule' => null,
            'matches' => 0,
            'would_change' => 0,
            'rules_first' => 0,
            'suggest_only_first' => 0,
            ...$preview->forAll($user),
        ];
        $this->showApplyModal = true;
    }

    /**
     * Queues every active auto-apply rule, in order, in one run.
     */
    public function applyAllRules(): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        ApplyUserRulesJob::dispatch($user);

        $this->closeApplyModal();

        Flux::toast(text: 'Applying all rules in the background', variant: 'success');
    }

    /**
     * Queues the apply: the active rules above this one run first, then this rule, which a queued
     * job also switches on. Nothing is changed in the request.
     */
    public function applyRule(int $ruleId): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        $rule = UserRule::where('id', $ruleId)
            ->where('user_id', $user->id)
            ->first();

        if (! $rule) {
            return;
        }

        ApplyUserRulesJob::dispatch($user, $rule->id);

        $this->closeApplyModal();

        Flux::toast(text: 'Applying in the background — the rules above run first', variant: 'success');
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
            'ruleCount' => UserRule::where('user_id', auth()->id())->count(),
            'hasActiveRules' => UserRule::where('user_id', auth()->id())
                ->active()
                ->whereHas('group', fn (Builder $q) => $q->where('is_active', true))
                ->exists(),
            'groups' => UserRuleGroup::where('user_id', auth()->id())
                ->ordered()
                ->when($matchesSearch !== null, fn (Builder $q) => $q->whereHas('rules', $matchesSearch))
                ->with(['rules' => fn ($q) => $q->when($matchesSearch !== null, $matchesSearch)->ordered()])
                ->get(),
            'runHistory' => app(RuleRunHistory::class)->forUser((int) auth()->id()),
            'categories' => Category::visibleSortedByFullPath((int) auth()->id()),
            'userTags' => Tag::where('user_id', auth()->id())->orderBy('name')->get(['id', 'name']),
            'actionTypes' => $this->offeredActionTypes(),
            'transferAccounts' => Account::where('user_id', auth()->id())->active()->orderBy('name')->get(),
            'plannedTransactions' => PlannedTransaction::where('user_id', auth()->id())
                ->where('is_active', true)
                ->orderBy('description')
                ->get(),
        ]);
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return app(RuleFormRules::class)->messages();
    }

    /**
     * Saves the rule form. The rule row stays locked until the transaction commits, and an email
     * rule that starts listening records its baseline in that same transaction, so a rule is never
     * active without its baseline. A baseline that times out aborts the transaction; the caller
     * then saves again with the rule left off.
     *
     * @return 'created'|'updated'|null null when the rule or its group is not the user's
     *
     * @throws RuleBaselineTimedOutException
     */
    private function persistRule(bool $forceInactive): ?string
    {
        $isActive = $forceInactive ? false : $this->ruleIsActive;

        if ($this->editingRuleId) {
            $rule = UserRule::where('id', $this->editingRuleId)
                ->where('user_id', auth()->id())
                ->lockForUpdate()
                ->first();

            if (! $rule) {
                return null;
            }

            $wasListening = $rule->is_active && $this->hasEmailAction($rule->actions);

            $rule->update([
                'name' => $this->ruleName,
                'description' => $this->ruleDescription ?: null,
                'triggers' => $this->triggers,
                'actions' => $this->normalisedActions(),
                'strict_mode' => $this->ruleStrictMode,
                'is_auto_apply' => $this->ruleIsAutoApply,
                'is_active' => $isActive,
                'effective_from' => $this->ruleEffectiveFrom ?: null,
            ]);

            if (! $wasListening) {
                $this->recordBaseline($rule);
            }

            return 'updated';
        }

        $group = UserRuleGroup::where('id', $this->ruleGroupId)
            ->where('user_id', auth()->id())
            ->first();

        if (! $group) {
            return null;
        }

        $maxOrder = UserRule::where('user_rule_group_id', $group->id)->max('order') ?? -1;

        $created = UserRule::create([
            'user_id' => auth()->id(),
            'user_rule_group_id' => $group->id,
            'name' => $this->ruleName,
            'description' => $this->ruleDescription ?: null,
            'triggers' => $this->triggers,
            'actions' => $this->normalisedActions(),
            'strict_mode' => $this->ruleStrictMode,
            'is_auto_apply' => $this->ruleIsAutoApply,
            'is_active' => $isActive,
            'effective_from' => $this->ruleEffectiveFrom ?: null,
            'order' => $maxOrder + 1,
        ]);

        $this->recordBaseline($created);

        return 'created';
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

    private function resetImport(): void
    {
        $this->reset('importFile', 'importSummary', 'importErrors');
        $this->resetValidation();
    }

    private function closeApplyModal(): void
    {
        $this->showApplyModal = false;
        $this->applyingRuleId = null;
        $this->applyPreview = null;
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
        $this->ruleEffectiveFrom = '';
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
        return [
            ...app(RuleFormRules::class)->forDraft($this->triggers, $this->actions, (int) auth()->id(), $this->aiActionAllowed()),
            'ruleEffectiveFrom' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * The actions as stored: an add-tags action keeps its tag ids as integers.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalisedActions(): array
    {
        return array_map(static function (array $action): array {
            if (($action['type'] ?? null) !== RuleActionType::AddTags->value) {
                return $action;
            }

            $ids = is_array($action['tag_ids'] ?? null) ? $action['tag_ids'] : [];

            return ['type' => RuleActionType::AddTags->value, 'value' => '', 'tag_ids' => array_values(array_unique(array_map(intval(...), $ids)))];
        }, $this->actions);
    }

    /**
     * The AI categorise action is offered only while the feature is available, or when the rule
     * being edited already has it.
     *
     * @return list<RuleActionType>
     */
    private function offeredActionTypes(): array
    {
        $hasAi = RuleAiCategorisation::available() || $this->formHasAiAction();

        return collect(RuleActionType::cases())
            ->reject(static fn (RuleActionType $type): bool => $type === RuleActionType::SuggestCategoryWithAi && ! $hasAi)
            ->values()
            ->all();
    }

    private function formHasAiAction(): bool
    {
        return array_any($this->actions, static fn (array $action): bool => ($action['type'] ?? null) === RuleActionType::SuggestCategoryWithAi->value)
            && $this->editingRuleId !== null
            && array_any(
                UserRule::query()->where('user_id', auth()->id())->whereKey($this->editingRuleId)->value('actions') ?? [],
                static fn (array $action): bool => ($action['type'] ?? null) === RuleActionType::SuggestCategoryWithAi->value,
            );
    }

    private function aiActionAllowed(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === RuleActionType::SuggestCategoryWithAi->value && ! RuleAiCategorisation::available() && ! $this->formHasAiAction()) {
                $fail('The AI categorise action is not available.');
            }
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $actions
     */
    private function hasEmailAction(array $actions): bool
    {
        return array_any($actions, static fn (array $action): bool => ($action['type'] ?? null) === RuleActionType::EmailDigest->value);
    }

    /**
     * A rule that is listening records the transactions it already matches as delivered, so its
     * first email covers only new ones. Called inside the transaction that switched it on, so a
     * timeout rolls the activation back instead of emailing the backlog.
     *
     * @throws RuleBaselineTimedOutException
     */
    private function recordBaseline(UserRule $rule): void
    {
        if ($rule->is_active && $this->hasEmailAction($rule->actions)) {
            app(RuleEmailBaseline::class)->record($rule);
        }
    }

    private function swapOrder(UserRuleGroup|UserRule $a, UserRuleGroup|UserRule $b): void
    {
        $tempOrder = $a->order;
        $a->update(['order' => $b->order]);
        $b->update(['order' => $tempOrder]);
    }
}
