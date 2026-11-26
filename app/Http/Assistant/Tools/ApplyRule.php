<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Contracts\AssistantWriteTool;
use App\Models\User;
use App\Services\Assistant\RuleApplyTokens;
use App\Services\RuleTester;
use App\Services\UserRuleApplier;

final class ApplyRule implements AssistantWriteTool
{
    use DefinesRules;

    public function __construct(
        private readonly RuleTester $tester,
        private readonly RuleApplyTokens $tokens,
        private readonly UserRuleApplier $applier,
        private readonly PreviewRule $preview,
    ) {}

    public function name(): string
    {
        return 'apply_rule';
    }

    public function description(): string
    {
        return 'Applies a rule to the user\'s transaction history, but only with the apply_token from a fresh preview_rule of the same, unedited rule. Without a valid token nothing is applied and a new preview is returned instead.';
    }

    public function requiresPreview(): bool
    {
        return false;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => ['rule_id' => ['type' => 'integer'], 'apply_token' => ['type' => 'string']],
            'required' => ['rule_id', 'apply_token'],
        ];
    }

    public function handle(User $user, array $arguments): array
    {
        $data = $this->validated($arguments, ['rule_id' => ['required', 'integer', 'min:1'], 'apply_token' => ['required', 'string', 'max:500']]);

        if (is_string($data)) {
            return ['error' => $data];
        }

        return $this->attempt(function () use ($user, $data): array {
            $rule = $this->ownedRule($user, $data['rule_id']);
            $current = $this->tester->test($user->id, $rule->triggers, $rule->strict_mode, $rule->actions, $rule->effective_from?->toDateString())->notYetAppliedCount;

            if (! $this->tokens->accepts($data['apply_token'], $user, $rule, $current) || ! $this->tokens->consume($data['apply_token'])) {
                return ['applied' => false, 'reason' => 'The apply token is missing, expired or out of date, so nothing was applied. Review this fresh preview with the user.'] + $this->preview->preview($user, $rule);
            }

            $handled = $this->applier->applyToHistory($rule);
            $this->audited($user, 'rule', $rule->id);

            return ['applied' => true, 'rule_id' => $rule->id, 'transactions_handled' => $handled];
        });
    }
}
