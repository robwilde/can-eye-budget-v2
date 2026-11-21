<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RuleTriggerField;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Support\Rules\RuleRunOrder;
use App\Support\Rules\RuleTriggers;

/**
 * The numbers an apply confirmation shows before anything is queued. Reads only; writes nothing.
 * Single-rule counts come from RuleTester, the dry run the rule form uses. "Apply all" counts the
 * transactions that any rule the job would apply matches, each once however many rules match it.
 */
final readonly class RuleApplyPreview
{
    public function __construct(
        private RuleTester $tester,
        private UserRuleApplier $applier,
        private RuleEvaluator $evaluator,
        private float $overallSeconds = 20.0,
    ) {}

    /**
     * @return array{matches: int, would_change: int, rules_first: int, suggest_only_first: int}
     */
    public function forRule(User $user, UserRule $rule): array
    {
        $result = $this->tester->test($user->id, $rule->triggers, $rule->strict_mode, $rule->actions, $rule->effective_from?->toDateString(), overrideLocks: true);
        $above = array_filter(RuleRunOrder::forUser($user->id, $rule), static fn (UserRule $candidate): bool => $candidate->id !== $rule->id);

        return [
            'matches' => $result->totalMatches(),
            'would_change' => $result->notYetAppliedCount,
            'rules_first' => count($above),
            'suggest_only_first' => count(array_filter($above, static fn (UserRule $candidate): bool => ! $candidate->is_auto_apply)),
        ];
    }

    /**
     * One pass over the user's history, each transaction checked against every auto-apply rule, under
     * one overall deadline. A pass that hits the deadline has not fully checked any rule, so every
     * rule is named and the count covers only the transactions checked before it stopped.
     *
     * @return array{transactions: int, rules: int, skipped: list<string>}
     */
    public function forAll(User $user): array
    {
        $rules = array_values(array_filter(RuleRunOrder::forUser($user->id), static fn (UserRule $rule): bool => $rule->is_auto_apply));

        if ($rules === []) {
            return ['transactions' => 0, 'rules' => 0, 'skipped' => []];
        }

        $usesTags = array_any($rules, static fn (UserRule $rule): bool => RuleTriggers::usesField($rule->triggers, RuleTriggerField::Tag->value));
        $deadline = microtime(true) + $this->overallSeconds;
        $matched = [];
        $finished = true;

        $this->applier->eligible($user->id)
            ->when($usesTags, static fn ($query) => $query->with('tags'))
            ->lazyById()
            ->each(function (Transaction $transaction) use ($rules, $deadline, &$matched, &$finished): ?bool {
                if (microtime(true) > $deadline) {
                    $finished = false;

                    return false;
                }

                if (array_any($rules, fn (UserRule $rule): bool => $this->evaluator->matches($transaction, $rule))) {
                    $matched[$transaction->id] = true;
                }

                return null;
            });

        return [
            'transactions' => count($matched),
            'rules' => $finished ? count($rules) : 0,
            'skipped' => $finished ? [] : array_map(static fn (UserRule $rule): string => $rule->name, $rules),
        ];
    }
}
