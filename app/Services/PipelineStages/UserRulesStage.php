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
use Illuminate\Support\Collection;

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

        $transactions = Transaction::query()
            ->where('user_id', $context->user->id)
            ->current()
            ->get();

        if ($transactions->isEmpty()) {
            return new StageResult(success: true, stage: self::STAGE_KEY);
        }

        $appliedRules = $this->loadPreviouslyAppliedRules($context);
        $suggestionIds = [];

        foreach ($transactions as $transaction) {
            $this->processTransaction($transaction, $groups, $context, $suggestionIds, $appliedRules);
        }

        return new StageResult(success: true, stage: self::STAGE_KEY, suggestionIds: $suggestionIds);
    }

    /**
     * @param  Collection<int, UserRuleGroup>  $groups
     * @param  list<int>  $suggestionIds
     * @param  array<string, bool>  $appliedRules
     */
    private function processTransaction(
        Transaction $transaction,
        Collection $groups,
        PipelineContext $context,
        array &$suggestionIds,
        array $appliedRules,
    ): void {
        foreach ($groups as $group) {
            $matched = $this->processGroup($transaction, $group, $context, $suggestionIds, $appliedRules);

            if ($matched && $group->stop_processing) {
                break;
            }
        }
    }

    /**
     * @param  list<int>  $suggestionIds
     * @param  array<string, bool>  $appliedRules
     */
    private function processGroup(
        Transaction $transaction,
        UserRuleGroup $group,
        PipelineContext $context,
        array &$suggestionIds,
        array $appliedRules,
    ): bool {
        $matched = false;

        foreach ($group->rules as $rule) {
            if (! $this->evaluator->matches($transaction, $rule)) {
                continue;
            }

            $matched = true;

            if ($rule->is_auto_apply) {
                $this->autoApplyRule($transaction, $rule, $context, $appliedRules);
            } else {
                $suggestionIds[] = $this->createRuleSuggestion($transaction, $rule, $context);
            }
        }

        return $matched;
    }

    /** @param  array<string, bool>  $appliedRules */
    private function autoApplyRule(
        Transaction $transaction,
        UserRule $rule,
        PipelineContext $context,
        array $appliedRules,
    ): void {
        $key = $rule->id.':'.$transaction->id;

        if (isset($appliedRules[$key])) {
            return;
        }

        $this->executor->execute($transaction, $rule->actions);

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
