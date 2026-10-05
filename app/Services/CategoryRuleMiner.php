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
use BackedEnum;
use Closure;
use Illuminate\Support\Collection;

final class CategoryRuleMiner
{
    /** The rule group generated rules are filed under. */
    public const string GROUP_NAME = 'Auto-categorisation';

    public function __construct(
        private RuleEvaluator $evaluator,
        private CategoryRuleGenerator $generator,
        private CategorySeedRules $seedRules,
        private ManualContradictionChecker $contradictionChecker,
    ) {}

    /**
     * Candidates whose `description contains` trigger also matches rows the
     * user categorised themselves under a different category are narrowed by
     * one extra trigger when that separates them exactly, and otherwise
     * reported under `contradictions` instead of being created.
     *
     * @param  list<int>  $accountIds
     * @param  list<array{value: string, category_id: int, source: string, extra_triggers?: list<array{field: string, operator: string, value: string}>}>  $extraSeeds
     * @return array{candidates: list<array{value: string, category_id: int, source: string, extra_triggers?: list<array{field: string, operator: string, value: string}>}>, ambiguous: list<array{value: string, categories: list<int>}>, conflicts: list<array{value: string, candidate: int, rule_id: int, rule_category: int}>, contradictions: list<array{value: string, category_id: int, source: string, contradicting: int, contradicting_categories: array<string, int>}>, skippedSeeds: list<string>, unknownCategoryIds: list<int>}
     */
    public function mine(User $user, array $accountIds = [], array $extraSeeds = [], ?Closure $isRelevant = null): array
    {
        $activeRules = $this->activeRules($user);
        $categoryNames = Category::query()->pluck('name', 'id');

        $candidates = [];
        $ambiguous = [];
        $conflicts = [];
        /** @var array<string, list<Transaction>> $motivatingRows */
        $motivatingRows = [];

        foreach ($this->minedGroups($user, $accountIds) as $key => $group) {
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
            $motivatingRows[$key] = $group['rows'];
        }

        $seedResult = $this->seedRules->resolve();
        $seeds = $seedResult['resolved'];
        $skippedSeeds = $seedResult['skipped'];

        $entries = [...$seeds, ...$extraSeeds, ...$candidates];

        if ($isRelevant !== null) {
            $entries = array_values(array_filter($entries, $isRelevant));
        }

        $deduped = $this->dedupeAndSubsume($entries, $this->existingTriggerValues($activeRules));

        $final = [];
        $contradictions = [];

        foreach ($deduped as $entry) {
            $motivating = $entry['source'] === 'mined' ? ($motivatingRows[mb_strtolower($entry['value'])] ?? []) : [];
            $resolved = $this->resolveContradictions($user->id, $entry, $motivating);

            if (isset($resolved['contradicting'])) {
                $contradictions[] = $resolved;
            } else {
                $final[] = $resolved;
            }
        }

        $unknownCategories = $this->unknownCategoryIds($final, $categoryNames);

        return [
            'candidates' => $final,
            'ambiguous' => $ambiguous,
            'conflicts' => $conflicts,
            'contradictions' => $contradictions,
            'skippedSeeds' => $skippedSeeds,
            'unknownCategoryIds' => $unknownCategories,
        ];
    }

    /**
     * The strict-mode triggers a candidate's rule carries: the description
     * match plus any narrowing trigger. Shared by createRules() and callers
     * that simulate candidates without writing them.
     *
     * @param  array{value: string, category_id: int, source: string, extra_triggers?: list<array{field: string, operator: string, value: string}>}  $entry
     * @return list<array{field: string, operator: string, value: string}>
     */
    public function triggersFor(array $entry): array
    {
        return [
            [
                'field' => RuleTriggerField::Description->value,
                'operator' => RuleTriggerOperator::Contains->value,
                'value' => $entry['value'],
            ],
            ...($entry['extra_triggers'] ?? []),
        ];
    }

    /**
     * @param  list<array{value: string, category_id: int, source: string, extra_triggers?: list<array{field: string, operator: string, value: string}>}>  $entries
     */
    public function createRules(User $user, array $entries): int
    {
        return count($this->createRuleModels($user, $entries));
    }

    /**
     * @param  list<array{value: string, category_id: int, source: string, extra_triggers?: list<array{field: string, operator: string, value: string}>}>  $entries
     * @return list<UserRule>
     */
    public function createRuleModels(User $user, array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        $group = $this->resolveGroup($user->id);
        $order = (int) UserRule::query()->where('user_rule_group_id', $group->id)->max('order');
        $rules = [];

        foreach ($entries as $entry) {
            $rules[] = UserRule::query()->create([
                'user_id' => $user->id,
                'user_rule_group_id' => $group->id,
                'name' => mb_substr('Categorise '.$entry['value'], 0, 255),
                'triggers' => $this->triggersFor($entry),
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

        return $rules;
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
     * @return array<string, array{value: string, category_ids: array<int, bool>, sample: Transaction, rows: list<Transaction>}>
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
                    $groups[$key] = ['value' => $value, 'category_ids' => [], 'sample' => $transaction, 'rows' => []];
                }

                $groups[$key]['category_ids'][(int) $transaction->category_id] = true;
                $groups[$key]['rows'][] = $transaction;
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
     * Check a candidate against the user's manual categorisations. Returns the
     * entry unchanged when nothing contradicts it, the entry with one extra
     * trigger when that trigger excludes every contradicting row while keeping
     * every row that must stay matched, or a contradiction report otherwise.
     *
     * Rows that must stay matched are the agreeing manual rows plus the mined
     * group that motivated the candidate; a seed with no agreeing manual rows
     * has nothing to anchor a narrowing to and is reported rather than guessed.
     *
     * @param  array{value: string, category_id: int, source: string, extra_triggers?: list<array{field: string, operator: string, value: string}>}  $entry
     * @param  list<Transaction>  $motivating
     * @return array{value: string, category_id: int, source: string, extra_triggers?: list<array{field: string, operator: string, value: string}>}|array{value: string, category_id: int, source: string, contradicting: int, contradicting_categories: array<string, int>}
     */
    private function resolveContradictions(int $userId, array $entry, array $motivating): array
    {
        $check = $this->contradictionChecker->check($userId, $entry['category_id'], $this->triggersFor($entry));

        if ($check['contradicts']->isEmpty()) {
            return $entry;
        }

        $keep = [...$check['agrees']->all(), ...$motivating];
        $narrowing = $keep === [] ? null : $this->narrowingTrigger($keep, $check['contradicts']->all());

        if ($narrowing !== null) {
            return [...$entry, 'extra_triggers' => [...($entry['extra_triggers'] ?? []), $narrowing]];
        }

        return [
            'value' => $entry['value'],
            'category_id' => $entry['category_id'],
            'source' => $entry['source'],
            'contradicting' => $check['contradicts']->count(),
            'contradicting_categories' => $check['contradictingCategories'],
        ];
    }

    /**
     * The first single trigger — direction, then account, then an amount
     * threshold — that every kept row satisfies and no contradicting row does.
     *
     * @param  non-empty-list<Transaction>  $keep
     * @param  non-empty-list<Transaction>  $contradicting
     * @return array{field: string, operator: string, value: string}|null
     */
    private function narrowingTrigger(array $keep, array $contradicting): ?array
    {
        foreach ([RuleTriggerField::Direction, RuleTriggerField::AccountId] as $field) {
            $kept = array_unique(array_map(fn (Transaction $t): string => $this->fieldValue($t, $field), $keep));

            if (count($kept) !== 1) {
                continue;
            }

            $value = $kept[array_key_first($kept)];

            if (array_any($contradicting, fn (Transaction $t): bool => $this->fieldValue($t, $field) === $value)) {
                continue;
            }

            return ['field' => $field->value, 'operator' => RuleTriggerOperator::Is->value, 'value' => $value];
        }

        $keptAmounts = array_map(static fn (Transaction $t): int => (int) $t->amount, $keep);
        $contraAmounts = array_map(static fn (Transaction $t): int => (int) $t->amount, $contradicting);

        // Kept rows all below the contradicting ones: cut at the gap's midpoint.
        if (max($keptAmounts) < min($contraAmounts)) {
            $threshold = (int) floor((max($keptAmounts) + min($contraAmounts)) / 2);

            return ['field' => RuleTriggerField::Amount->value, 'operator' => RuleTriggerOperator::LessThanOrEqual->value, 'value' => (string) $threshold];
        }

        if (min($keptAmounts) > max($contraAmounts)) {
            $threshold = (int) floor((max($contraAmounts) + min($keptAmounts) + 1) / 2);

            return ['field' => RuleTriggerField::Amount->value, 'operator' => RuleTriggerOperator::GreaterThanOrEqual->value, 'value' => (string) $threshold];
        }

        return null;
    }

    /** The trigger-comparable string form of a direction or account id. */
    private function fieldValue(Transaction $transaction, RuleTriggerField $field): string
    {
        $raw = $transaction->{$field->value};

        return $raw instanceof BackedEnum ? (string) $raw->value : (string) $raw;
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
     * @param  list<array{value: string, category_id: int, source: string, extra_triggers?: list<array{field: string, operator: string, value: string}>}>  $entries
     * @param  array<string, true>  $existingValues
     * @return list<array{value: string, category_id: int, source: string, extra_triggers?: list<array{field: string, operator: string, value: string}>}>
     */
    private function dedupeAndSubsume(array $entries, array $existingValues): array
    {
        $byValue = [];
        $merged = [];

        foreach ($entries as $entry) {
            $key = mb_strtolower($entry['value']);

            if (isset($existingValues[$key])) {
                continue;
            }

            foreach ($byValue[$key] ?? [] as $kept) {
                if ($kept['category_id'] === $entry['category_id'] || ($entry['extra_triggers'] ?? []) === []) {
                    continue 2;
                }
            }

            $byValue[$key][] = $entry;
            $merged[] = $entry;
        }

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

        return $existing ?? UserRuleGroup::query()->create([
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
