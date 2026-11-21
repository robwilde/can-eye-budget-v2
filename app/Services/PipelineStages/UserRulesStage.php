<?php

declare(strict_types=1);

namespace App\Services\PipelineStages;

use App\Contracts\PipelineStageContract;
use App\DTOs\PipelineContext;
use App\DTOs\StageResult;
use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineAuditEntry;
use App\Models\Transaction;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Services\RuleActionExecutor;
use App\Services\RuleEvaluator;
use App\Services\RuleFieldClaims;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class UserRulesStage implements PipelineStageContract
{
    private const string STAGE_KEY = 'user-rules';

    public function __construct(
        private RuleEvaluator $evaluator,
        private RuleActionExecutor $executor,
    ) {}

    public function key(): string
    {
        return self::STAGE_KEY;
    }

    public function label(): string
    {
        return 'User Rules';
    }

    public function shouldRun(PipelineContext $context): bool
    {
        return true;
    }

    public function execute(PipelineContext $context): StageResult
    {
        $groups = UserRuleGroup::query()
            ->where('user_id', $context->user->id)
            ->active()
            ->ordered()
            ->with(['rules' => fn ($q) => $q->active()->ordered()])
            ->get();

        if ($groups->isEmpty()) {
            return new StageResult(success: true, stage: self::STAGE_KEY);
        }

        // Transactions are loaded once, upfront. A merged child created mid-run
        // (by a fold action) is therefore not rule-processed until the next
        // pipeline run — an accepted limitation.
        $transactions = Transaction::query()
            ->where('user_id', $context->user->id)
            ->current()
            ->get();

        if ($transactions->isEmpty()) {
            return new StageResult(success: true, stage: self::STAGE_KEY);
        }

        $appliedRules = $this->loadPreviouslyAppliedRules($context);
        $suggestionIds = [];
        $blocked = [];
        $failed = [];
        $claims = new RuleFieldClaims;

        foreach ($transactions as $transaction) {
            $this->processTransaction($transaction, $groups, $context, $suggestionIds, $appliedRules, $blocked, $failed, $claims);
        }

        $this->recordBlocked($context, $blocked);
        $this->recordFailed($context, $failed);

        return new StageResult(success: true, stage: self::STAGE_KEY, suggestionIds: $suggestionIds);
    }

    /**
     * @param  Collection<int, UserRuleGroup>  $groups
     * @param  list<int>  $suggestionIds
     * @param  array<string, bool>  $appliedRules
     * @param  array<string, int>  $blocked
     * @param  array<int, array{rule: UserRule, count: int, exception: class-string<Throwable>}>  $failed
     */
    private function processTransaction(
        Transaction $transaction,
        Collection $groups,
        PipelineContext $context,
        array &$suggestionIds,
        array $appliedRules,
        array &$blocked,
        array &$failed,
        RuleFieldClaims $claims,
    ): void {
        foreach ($groups as $group) {
            $matched = $this->processGroup($transaction, $group, $context, $suggestionIds, $appliedRules, $blocked, $failed, $claims);

            if ($matched && $group->stop_processing) {
                break;
            }
        }
    }

    /**
     * @param  list<int>  $suggestionIds
     * @param  array<string, bool>  $appliedRules
     * @param  array<string, int>  $blocked
     * @param  array<int, array{rule: UserRule, count: int, exception: class-string<Throwable>}>  $failed
     */
    private function processGroup(
        Transaction $transaction,
        UserRuleGroup $group,
        PipelineContext $context,
        array &$suggestionIds,
        array $appliedRules,
        array &$blocked,
        array &$failed,
        RuleFieldClaims $claims,
    ): bool {
        $matched = false;

        foreach ($group->rules as $rule) {
            if (! $this->evaluator->matches($transaction, $rule)) {
                continue;
            }

            $matched = true;

            if ($rule->is_auto_apply) {
                $this->autoApplyRule($transaction, $rule, $context, $appliedRules, $blocked, $failed, $claims);
            } else {
                $suggestionIds[] = $this->createRuleSuggestion($transaction, $rule, $context);
            }
        }

        return $matched;
    }

    /**
     * @param  array<string, bool>  $appliedRules
     * @param  array<string, int>  $blocked
     * @param  array<int, array{rule: UserRule, count: int, exception: class-string<Throwable>}>  $failed
     */
    private function autoApplyRule(
        Transaction $transaction,
        UserRule $rule,
        PipelineContext $context,
        array $appliedRules,
        array &$blocked,
        array &$failed,
        RuleFieldClaims $claims,
    ): void {
        $actions = $claims->unclaimed($transaction->id, $rule->actions);

        try {
            if (! isset($appliedRules[$rule->id.':'.$transaction->id]) && $actions !== []) {
                $this->applyActions($transaction, $rule, $actions, $context, $blocked);
            }
        } catch (Throwable $exception) {
            report($exception);

            // The failed rule's unsaved changes must not leak into the next rule's save.
            rescue(static fn (): Transaction => $transaction->refresh(), report: false);

            $failed[$rule->id] = [
                'rule' => $rule,
                'count' => ($failed[$rule->id]['count'] ?? 0) + 1,
                'exception' => $exception::class,
            ];
        }

        $claims->claim($transaction->id, $rule->actions, fn (array $action): bool => $this->executor->canAct($transaction, $action));
    }

    /**
     * @param  array<int, array<string, string>>  $actions
     * @param  array<string, int>  $blocked
     */
    private function applyActions(
        Transaction $transaction,
        UserRule $rule,
        array $actions,
        PipelineContext $context,
        array &$blocked,
    ): void {
        // One transaction per rule and row: a rule that fails part-way, say a later action
        // after an earlier one saved, leaves nothing half applied.
        DB::transaction(function () use ($transaction, $rule, $actions, $context, &$blocked): void {
            $this->applyActionsInTransaction($transaction, $rule, $actions, $context, $blocked);
        });
    }

    /**
     * @param  array<int, array<string, string>>  $actions
     * @param  array<string, int>  $blocked
     */
    private function applyActionsInTransaction(
        Transaction $transaction,
        UserRule $rule,
        array $actions,
        PipelineContext $context,
        array &$blocked,
    ): void {
        $key = $rule->id.':'.$transaction->id;

        $lockedOut = $this->executor->blockedByLocks($transaction, $actions);
        $applied = $this->executor->execute($transaction, $actions, overwriteCleanDescription: false);

        if ($lockedOut) {
            $blocked[$key] = $transaction->id;
        }

        // Only record the audit entry when the rule actually took effect. An
        // ineffective auto-apply (e.g. an orphan fee whose parent has not been
        // imported yet) is left unrecorded so the next pipeline run retries it.
        if (! $applied) {
            return;
        }

        PipelineAuditEntry::create([
            'pipeline_run_id' => $context->pipelineRun->id,
            'stage' => self::STAGE_KEY,
            'action' => 'auto_applied',
            'metadata' => [
                'rule_id' => $rule->id,
                'rule_name' => $rule->name,
                'transaction_id' => $transaction->id,
                'actions' => $rule->actions,
            ],
        ]);
    }

    /**
     * A failed rule is recorded once per run, with how many transactions it failed on and the
     * exception class only. It still claimed its fields, and the rules below it ran.
     *
     * @param  array<int, array{rule: UserRule, count: int, exception: class-string<Throwable>}>  $failed
     */
    private function recordFailed(PipelineContext $context, array $failed): void
    {
        foreach ($failed as $ruleId => $failure) {
            PipelineAuditEntry::create([
                'pipeline_run_id' => $context->pipelineRun->id,
                'stage' => self::STAGE_KEY,
                'action' => 'rule_failed',
                'metadata' => [
                    'rule_id' => $ruleId,
                    'rule_name' => $failure['rule']->name,
                    'transactions' => $failure['count'],
                    'exception' => $failure['exception'],
                ],
            ]);
        }
    }

    /** @param  array<string, int>  $blocked */
    private function recordBlocked(PipelineContext $context, array $blocked): void
    {
        if ($blocked === []) {
            return;
        }

        PipelineAuditEntry::create([
            'pipeline_run_id' => $context->pipelineRun->id,
            'stage' => self::STAGE_KEY,
            'action' => 'blocked_by_lock',
            'metadata' => [
                'count' => count(array_unique($blocked)),
                'transaction_ids' => array_values(array_unique($blocked)),
            ],
        ]);
    }

    private function createRuleSuggestion(
        Transaction $transaction,
        UserRule $rule,
        PipelineContext $context,
    ): int {
        $suggestion = AnalysisSuggestion::create([
            'user_id' => $context->user->id,
            'pipeline_run_id' => $context->pipelineRun->id,
            'type' => SuggestionType::UserRule,
            'status' => SuggestionStatus::Pending,
            'payload' => [
                'rule_id' => $rule->id,
                'rule_name' => $rule->name,
                'transaction_id' => $transaction->id,
                'actions' => $rule->actions,
            ],
        ]);

        PipelineAuditEntry::create([
            'pipeline_run_id' => $context->pipelineRun->id,
            'stage' => self::STAGE_KEY,
            'action' => 'suggestion_created',
            'metadata' => [
                'rule_id' => $rule->id,
                'rule_name' => $rule->name,
                'transaction_id' => $transaction->id,
                'suggestion_id' => $suggestion->id,
            ],
        ]);

        return $suggestion->id;
    }

    /** @return array<string, bool> */
    private function loadPreviouslyAppliedRules(PipelineContext $context): array
    {
        return PipelineAuditEntry::query()
            ->where('stage', self::STAGE_KEY)
            ->where('action', 'auto_applied')
            ->whereHas('pipelineRun', fn ($q) => $q->where('user_id', $context->user->id))
            ->pluck('metadata')
            ->mapWithKeys(fn (array $meta): array => [
                $meta['rule_id'].':'.$meta['transaction_id'] => true,
            ])
            ->all();
    }
}
