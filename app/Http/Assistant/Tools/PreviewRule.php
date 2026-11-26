<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Contracts\AssistantTool;
use App\Models\User;
use App\Models\UserRule;
use App\Services\Assistant\RuleApplyTokens;
use App\Services\RuleTester;

final class PreviewRule implements AssistantTool
{
    use DefinesRules;

    public function __construct(
        private readonly RuleTester $tester,
        private readonly RuleApplyTokens $tokens,
    ) {}

    public function name(): string
    {
        return 'preview_rule';
    }

    public function description(): string
    {
        return 'Shows how many of the user\'s transactions a rule matches and would change, without changing anything. Returns an apply_token valid for 30 minutes that apply_rule needs; editing the rule invalidates it.';
    }

    public function requiresPreview(): bool
    {
        return false;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => ['rule_id' => ['type' => 'integer']],
            'required' => ['rule_id'],
        ];
    }

    public function handle(User $user, array $arguments): array
    {
        $data = $this->validated($arguments, ['rule_id' => ['required', 'integer', 'min:1']]);

        if (is_string($data)) {
            return ['error' => $data];
        }

        return $this->attempt(fn (): array => $this->preview($user, $this->ownedRule($user, $data['rule_id'])));
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(User $user, UserRule $rule): array
    {
        $result = $this->tester->test($user->id, $rule->triggers, $rule->strict_mode, $rule->actions, $rule->effective_from?->toDateString());

        return [
            'rule_id' => $rule->id,
            'matches' => $result->totalMatches(),
            'would_change' => $result->notYetAppliedCount,
            'already_applied' => $result->alreadyAppliedCount,
            'skipped' => $result->skippedCount,
            'apply_token' => $this->tokens->issue($user, $rule, $result->notYetAppliedCount),
            'apply_token_expires_in_minutes' => RuleApplyTokens::LIFETIME_MINUTES,
        ];
    }
}
