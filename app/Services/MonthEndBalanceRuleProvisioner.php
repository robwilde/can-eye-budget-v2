<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RuleActionType;
use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;

final readonly class MonthEndBalanceRuleProvisioner
{
    public const string CATEGORY_NAME = 'Balance';

    public const string MATCH_VALUE = 'Month End Balance';

    private const string GROUP_NAME = 'Auto-categorisation';

    private const string RULE_NAME = 'Categorise month-end balance';

    public function __construct(
        private RuleEvaluator $evaluator,
        private RuleActionExecutor $executor,
    ) {}

    public function provisionAllUsers(): void
    {
        $categoryId = $this->balanceCategoryId();

        if ($categoryId === null) {
            return;
        }

        User::query()->eachById(function (User $user) use ($categoryId): void {
            $this->provision($user->id, $categoryId);
        });
    }

    public function provision(int $userId, int $categoryId): UserRule
    {
        $group = $this->group($userId);

        $rule = UserRule::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'user_rule_group_id' => $group->id,
                'name' => self::RULE_NAME,
            ],
            [
                'triggers' => [[
                    'field' => RuleTriggerField::Description->value,
                    'operator' => RuleTriggerOperator::Contains->value,
                    'value' => self::MATCH_VALUE,
                ]],
                'actions' => [[
                    'type' => RuleActionType::SetCategory->value,
                    'value' => (string) $categoryId,
                ]],
                'strict_mode' => true,
                'is_auto_apply' => true,
                'is_active' => true,
                'order' => (int) UserRule::query()->where('user_rule_group_id', $group->id)->max('order') + 1,
            ],
        );

        if ($rule->wasRecentlyCreated) {
            $this->applyToExisting($userId, $rule);
        }

        return $rule;
    }

    public function balanceCategoryId(): ?int
    {
        $id = Category::query()
            ->whereNull('parent_id')
            ->where('name', self::CATEGORY_NAME)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function applyToExisting(int $userId, UserRule $rule): void
    {
        Transaction::query()
            ->where('user_id', $userId)
            ->current()
            ->lazyById()
            ->each(function (Transaction $transaction) use ($rule): void {
                if ($this->evaluator->matches($transaction, $rule)) {
                    $this->executor->execute($transaction, $rule->actions);
                }
            });
    }

    private function group(int $userId): UserRuleGroup
    {
        return UserRuleGroup::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'name' => self::GROUP_NAME,
            ],
            [
                'order' => (int) UserRuleGroup::query()->where('user_id', $userId)->max('order') + 1,
                'is_active' => true,
                'stop_processing' => false,
            ],
        );
    }
}
