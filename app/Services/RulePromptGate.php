<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RuleActionType;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use Carbon\CarbonImmutable;

/**
 * Decides whether a "Create rule" prompt is worth showing after a person changes a
 * transaction's category: not when the user switched prompts off or paused them, and not
 * when an active rule that sets this category already matches the transaction.
 */
final readonly class RulePromptGate
{
    public function __construct(private RuleEvaluator $evaluator) {}

    public function shouldOffer(User $user, Transaction $transaction, int $categoryId): bool
    {
        $preferences = $user->preferences;

        if (! $preferences->rulePromptEnabled) {
            return false;
        }

        if ($preferences->rulePromptHiddenUntil !== null && $preferences->rulePromptHiddenUntil->greaterThan(CarbonImmutable::now())) {
            return false;
        }

        return ! $this->activeRuleAlreadySets($user, $transaction, $categoryId);
    }

    private function activeRuleAlreadySets(User $user, Transaction $transaction, int $categoryId): bool
    {
        return UserRule::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereHas('group', fn ($group) => $group->where('is_active', true))
            ->get()
            ->contains(fn (UserRule $rule): bool => $this->setsCategory($rule, $categoryId)
                && $this->evaluator->matches($transaction, $rule));
    }

    private function setsCategory(UserRule $rule, int $categoryId): bool
    {
        return array_any($rule->actions, static fn (array $action): bool => ($action['type'] ?? null) === RuleActionType::SetCategory->value
            && (int) ($action['value'] ?? 0) === $categoryId);
    }
}
