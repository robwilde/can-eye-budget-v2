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
use App\Support\Transactions\MerchantMatchValue;
use Illuminate\Support\Collection;

final class CategoryRuleMiner
{
    /** The rule group generated rules are filed under. */
    public const string GROUP_NAME = 'Auto-categorisation';

    public function __construct(
        private RuleEvaluator $evaluator,
        private CategoryRuleGenerator $generator,
        private CategorySeedRules $seedRules,
    ) {}

    /**
     * @param  list<int>  $accountIds
     * @return array{candidates: list<array{value: string, category_id: int, source: string}>, ambiguous: list<array{value: string, categories: list<int>}>, conflicts: list<array{value: string, candidate: int, rule_id: int, rule_category: int}>, skippedSeeds: list<string>, unknownCategoryIds: list<int>}
     */
    public function mine(User $user, array $accountIds = []): array
    {
        $activeRules = $this->activeRules($user);
        $categoryNames = Category::query()->pluck('name', 'id');

        $candidates = [];
        $ambiguous = [];
        $conflicts = [];

        foreach ($this->minedGroups($user, $accountIds) as $group) {
            $categoryIds = array_keys($group['category_ids']);

            if (count($categoryIds) > 1) {
                sort($categoryIds);
                $ambiguous[] = ['value' => $group['value'], 'categories' => $categoryIds];

                continue;
            }

            $categoryId = $categoryIds[0];
            $matchingCategories = $this->matchingRuleCategories($group['sample'], $activeRules);

            if (isset($matchingCategories[$categoryId])) {
                continue;
            }

            if ($matchingCategories !== []) {
                $ruleCategory = array_key_first($matchingCategories);
                $conflicts[] = [
                    'value' => $group['value'],
                    'candidate' => $categoryId,
                    'rule_id' => $matchingCategories[$ruleCategory]->id,
                    'rule_category' => $ruleCategory,
                ];

                continue;
            }

            $candidates[] = ['value' => $group['value'], 'category_id' => $categoryId, 'source' => 'mined'];
        }

        $seedResult = $this->seedRules->resolve();
        $seeds = $seedResult['resolved'];
        $skippedSeeds = $seedResult['skipped'];

        $final = $this->dedupeAndSubsume([...$seeds, ...$candidates], $this->existingTriggerValues($activeRules));

        $unknownCategories = $this->unknownCategoryIds($final, $categoryNames);

        return [
            'candidates' => $final,
            'ambiguous' => $ambiguous,
            'conflicts' => $conflicts,
            'skippedSeeds' => $skippedSeeds,
            'unknownCategoryIds' => $unknownCategories,
        ];
    }

    /**
     * @param  list<array{value: string, category_id: int, source: string}>  $entries
     */
    public function createRules(User $user, array $entries): int
    {
        if ($entries === []) {
            return 0;
        }

        $group = $this->resolveGroup($user->id);
        $order = (int) UserRule::query()->where('user_rule_group_id', $group->id)->max('order');

        foreach ($entries as $entry) {
            UserRule::query()->create([
                'user_id' => $user->id,
                'user_rule_group_id' => $group->id,
                'name' => mb_substr('Categorise '.$entry['value'], 0, 255),
                'triggers' => [[
                    'field' => RuleTriggerField::Description->value,
                    'operator' => RuleTriggerOperator::Contains->value,
                    'value' => $entry['value'],
                ]],
                'actions' => [[
                    'type' => RuleActionType::SetCategory->value,
                    'value' => (string) $entry['category_id'],
                ]],
                'strict_mode' => true,
                'is_auto_apply' => true,
                'is_active' => true,
                'order' => ++$order,
            ]);
        }

        return count($entries);
    }

    /**
     * Active rules in active groups, in evaluation order.
     *
     * @return Collection<int, UserRule>
     */
    public function activeRules(User $user): Collection
    {
        return UserRule::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereHas('group', fn ($query) => $query->where('is_active', true))
            ->ordered()
            ->get();
    }

    /**
     * @param  list<int>  $accountIds
     * @return array<string, array{value: string, category_ids: array<int, bool>, sample: Transaction}>
     */
    private function minedGroups(User $user, array $accountIds): array
    {
        $groups = [];

        Transaction::query()
            ->where('user_id', $user->id)
            ->whereNotNull('category_id')
            ->whereNull('folded_into_transaction_id')
            ->current()
            ->when($accountIds !== [], fn ($query) => $query->whereIn('account_id', $accountIds))
            ->lazyById()
            ->each(function (Transaction $transaction) use (&$groups): void {
                $value = MerchantMatchValue::for($transaction->description) ?? $this->generator->suggestMatchValue($transaction);
                $key = mb_strtolower($value);

                if (! isset($groups[$key])) {
                    $groups[$key] = ['value' => $value, 'category_ids' => [], 'sample' => $transaction];
                }

                $groups[$key]['category_ids'][(int) $transaction->category_id] = true;
            });

        return $groups;
    }

    /**
     * @param  Collection<int, UserRule>  $activeRules
     * @return array<int, UserRule>
     */
    private function matchingRuleCategories(Transaction $sample, Collection $activeRules): array
    {
        $matches = [];

        foreach ($activeRules as $rule) {
            if (! $this->evaluator->matches($sample, $rule)) {
                continue;
            }

            $categoryId = $this->ruleCategory($rule);

            if ($categoryId !== null && ! isset($matches[$categoryId])) {
                $matches[$categoryId] = $rule;
            }
        }

        return $matches;
    }

    private function ruleCategory(UserRule $rule): ?int
    {
        foreach ($rule->actions as $action) {
            if (($action['type'] ?? null) === RuleActionType::SetCategory->value) {
                return (int) $action['value'];
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, UserRule>  $activeRules
     * @return array<string, true>
     */
    private function existingTriggerValues(Collection $activeRules): array
    {
        $values = [];

        foreach ($activeRules as $rule) {
            foreach ($rule->triggers as $trigger) {
                $value = $trigger['value'] ?? '';

                if ($value !== '') {
                    $values[mb_strtolower($value)] = true;
                }
            }
        }

        return $values;
    }

    /**
     * @param  list<array{value: string, category_id: int, source: string}>  $entries
     * @param  array<string, true>  $existingValues
     * @return list<array{value: string, category_id: int, source: string}>
     */
    private function dedupeAndSubsume(array $entries, array $existingValues): array
    {
        $byKey = [];

        foreach ($entries as $entry) {
            $key = mb_strtolower($entry['value']);

            if (isset($existingValues[$key]) || isset($byKey[$key])) {
                continue;
            }

            $byKey[$key] = $entry;
        }

        $merged = array_values($byKey);

        $final = [];

        foreach ($merged as $entry) {
            $subsumed = false;

            foreach ($merged as $other) {
                if ($other['category_id'] !== $entry['category_id']) {
                    continue;
                }

                if (mb_strtolower($other['value']) === mb_strtolower($entry['value'])) {
                    continue;
                }

                if (mb_stripos($entry['value'], $other['value']) !== false) {
                    $subsumed = true;
                    break;
                }
            }

            if (! $subsumed) {
                $final[] = $entry;
            }
        }

        return $final;
    }

    private function resolveGroup(int $userId): UserRuleGroup
    {
        $existing = UserRuleGroup::query()
            ->where('user_id', $userId)
            ->where('name', self::GROUP_NAME)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return UserRuleGroup::query()->create([
            'user_id' => $userId,
            'name' => self::GROUP_NAME,
            'order' => (int) UserRuleGroup::query()->where('user_id', $userId)->max('order') + 1,
            'is_active' => true,
            'stop_processing' => false,
        ]);
    }

    /**
     * @param  list<array{value: string, category_id: int, source: string}>  $final
     * @param  Collection<int, string>  $categoryNames
     * @return list<int>
     */
    private function unknownCategoryIds(array $final, Collection $categoryNames): array
    {
        $unknown = [];

        foreach ($final as $entry) {
            if (! $categoryNames->has($entry['category_id'])) {
                $unknown[$entry['category_id']] = true;
            }
        }

        $ids = array_keys($unknown);
        sort($ids);

        return $ids;
    }
}
