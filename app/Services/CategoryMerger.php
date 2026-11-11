<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\UserPreferences;
use App\Enums\RuleActionType;
use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Enums\SuggestionStatus;
use App\Exceptions\CategoryMergeRefusedException;
use App\Models\Category;
use App\Models\User;
use App\Support\Categories\ProtectedCategories;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

/**
 * Merges categories into one target, or deletes a category with or without a
 * replacement, rewriting every reference in one transaction. Every read and
 * rewrite is limited to the acting user's rows (ADR 0002), and the user's
 * category rows are locked in id order before anything is written.
 */
final class CategoryMerger
{
    private const int CHUNK = 200;

    /**
     * @var list<array{0: int, 1: int|null}>
     */
    private array $moves = [];

    /**
     * Merge the sources into the target one at a time, deepest first. Each
     * source's subcategories move under the target, and a subcategory whose
     * name clashes with one already there is merged into it, recursively.
     *
     * @param  list<int>  $sourceIds
     *
     * @throws CategoryMergeRefusedException|Throwable
     */
    public function merge(User $user, array $sourceIds, int $targetId): void
    {
        $sourceIds = array_values(array_unique($sourceIds));

        if ($sourceIds === []) {
            throw new CategoryMergeRefusedException(__('Choose at least one category to merge.'));
        }

        if (in_array($targetId, $sourceIds, true)) {
            throw new CategoryMergeRefusedException(__('A category cannot be merged into itself.'));
        }

        DB::transaction(function () use ($user, $sourceIds, $targetId): void {
            $this->moves = [];
            $tree = $this->lockedTree($user->id);
            $target = $tree->get($targetId) ?? throw new CategoryMergeRefusedException(__('The target category no longer exists.'));

            $this->assertMergeable($tree, $sourceIds, $target);

            $ordered = collect($sourceIds)
                ->sortBy([fn (int $a, int $b): int => $tree[$b]->depth() <=> $tree[$a]->depth(), fn (int $a, int $b): int => $a <=> $b])
                ->values();

            foreach ($ordered as $sourceId) {
                $source = Category::query()->where('user_id', $user->id)->find($sourceId);

                if ($source !== null) {
                    $this->absorb($user->id, $source, $target);
                }
            }

            $this->applyRecentPicks($user);
        });
    }

    /**
     * Delete the category. With a replacement its references move there;
     * without one they are cleared. Its subcategories become top-level, and
     * one whose name clashes with a top-level category is merged into it.
     *
     * @throws CategoryMergeRefusedException
     */
    public function deleteWithReplacement(User $user, int $categoryId, ?int $replacementId = null): void
    {
        DB::transaction(function () use ($user, $categoryId, $replacementId): void {
            $this->moves = [];
            $tree = $this->lockedTree($user->id);
            $category = $tree->get($categoryId) ?? throw new CategoryMergeRefusedException(__('That category no longer exists.'));

            if (ProtectedCategories::isProtected($category)) {
                throw new CategoryMergeRefusedException(__('This category is found by name elsewhere in the app, so it cannot be deleted.'));
            }

            if ($replacementId !== null && ($replacementId === $categoryId || ! $tree->has($replacementId))) {
                throw new CategoryMergeRefusedException(__('Choose a different category to replace it with.'));
            }

            $this->assertNoForeignReferences($user->id, $categoryId);
            $this->moveChildren($user->id, $categoryId, null);
            $replacementId = $this->resolveMoved($replacementId);
            $this->repoint($user->id, $categoryId, $replacementId);
            $this->moves[] = [$categoryId, $replacementId];
            Category::query()->where('user_id', $user->id)->whereKey($categoryId)->delete();

            $this->applyRecentPicks($user);
        });
    }

    /**
     * @return Collection<int, Category>
     */
    private function lockedTree(int $userId): Collection
    {
        User::query()->whereKey($userId)->lockForUpdate()->value('id');

        $tree = Category::query()->where('user_id', $userId)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($tree as $category) {
            $category->setRelation('parent', $category->parent_id !== null ? ($tree[$category->parent_id] ?? null) : null);
        }

        return $tree;
    }

    /**
     * @param  Collection<int, Category>  $tree
     * @param  list<int>  $sourceIds
     *
     * @throws CategoryMergeRefusedException
     */
    private function assertMergeable(Collection $tree, array $sourceIds, Category $target): void
    {
        foreach ($sourceIds as $sourceId) {
            $source = $tree->get($sourceId) ?? throw new CategoryMergeRefusedException(__('One of the categories no longer exists.'));

            if (ProtectedCategories::isProtected($source)) {
                throw new CategoryMergeRefusedException(__('This category is found by name elsewhere in the app, so it cannot be merged away.'));
            }

            for ($ancestor = $target->parent; $ancestor !== null; $ancestor = $ancestor->parent) {
                if ($ancestor->id === $sourceId) {
                    throw new CategoryMergeRefusedException(__('A category cannot be merged into one of its own subcategories.'));
                }
            }

            if ($target->parent_id !== null && $tree->contains(fn (Category $category): bool => $category->parent_id === $sourceId)) {
                throw new CategoryMergeRefusedException(__('A category with subcategories cannot be merged into a subcategory.'));
            }
        }
    }

    private function absorb(int $userId, Category $source, Category $target): void
    {
        $this->assertNoForeignReferences($userId, $source->id);
        $this->moveChildren($userId, $source->id, $target->id);
        $this->repoint($userId, $source->id, $target->id);
        $this->moves[] = [$source->id, $target->id];
        Category::query()->where('user_id', $userId)->whereKey($source->id)->delete();
    }

    /**
     * @throws CategoryMergeRefusedException
     */
    private function assertNoForeignReferences(int $userId, int $categoryId): void
    {
        $foreign = DB::table('transactions')->where('user_id', '!=', $userId)->where('category_id', $categoryId)->exists()
            || DB::table('transaction_splits')->where('category_id', $categoryId)->whereIn('transaction_id', fn (Builder $query) => $query->select('id')->from('transactions')->where('user_id', '!=', $userId))->exists()
            || DB::table('budgets')->where('user_id', '!=', $userId)->where('category_id', $categoryId)->exists()
            || DB::table('planned_transactions')->where('user_id', '!=', $userId)->where('category_id', $categoryId)->exists()
            || DB::table('bnpl_orders')->where('user_id', '!=', $userId)->where('category_id', $categoryId)->exists()
            || DB::table('payees')->where('user_id', '!=', $userId)->where(fn (Builder $query) => $query->where('suggested_category_id', $categoryId)->orWhere('confirmed_category_id', $categoryId))->exists()
            || DB::table('user_category_budget_tags')->where('user_id', '!=', $userId)->where('category_id', $categoryId)->exists();

        if ($foreign) {
            throw new CategoryMergeRefusedException(__('Another account still references this category, so it cannot be merged or deleted.'));
        }
    }

    private function resolveMoved(?int $categoryId): ?int
    {
        foreach ($this->moves as [$from, $to]) {
            if ($categoryId === $from) {
                $categoryId = $to;
            }
        }

        return $categoryId;
    }

    private function moveChildren(int $userId, int $fromId, ?int $toId): void
    {
        $children = Category::query()->where('user_id', $userId)->where('parent_id', $fromId)->orderBy('id')->get();

        foreach ($children as $child) {
            $sibling = Category::query()
                ->where('user_id', $userId)
                ->when($toId === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $toId))
                ->whereKeyNot([$child->id, $fromId])
                ->orderBy('id')
                ->get()
                ->first(fn (Category $candidate): bool => $this->sameName($candidate->name, $child->name));

            if ($sibling !== null) {
                $this->absorb($userId, $child, $sibling);

                continue;
            }

            Category::query()->where('user_id', $userId)->whereKey($child->id)->update(['parent_id' => $toId]);
        }
    }

    private function sameName(string $a, string $b): bool
    {
        return mb_strtolower(mb_trim($a)) === mb_strtolower(mb_trim($b));
    }

    private function repoint(int $userId, int $from, ?int $to): void
    {
        $ledger = $to === null ? ['category_id' => null] : ['category_id' => $to];

        DB::table('transactions')->where('user_id', $userId)->where('category_id', $from)
            ->update($to === null ? $ledger + ['category_source' => null] : $ledger);

        DB::table('transaction_splits')
            ->where('category_id', $from)
            ->whereIn('transaction_id', fn (Builder $query) => $query->select('id')->from('transactions')->where('user_id', $userId))
            ->update($ledger);

        $this->repointBudgets($userId, $from, $to);

        DB::table('planned_transactions')->where('user_id', $userId)->where('category_id', $from)->update($ledger);
        DB::table('bnpl_orders')->where('user_id', $userId)->where('category_id', $from)->update($ledger);
        DB::table('payees')->where('user_id', $userId)->where('suggested_category_id', $from)->update(['suggested_category_id' => $to]);
        DB::table('payees')->where('user_id', $userId)->where('confirmed_category_id', $from)->update(['confirmed_category_id' => $to]);

        $this->repointBudgetTag($userId, $from);
        $this->repointProbabilities($userId, $from, $to);
        $this->repointRules($userId, $from, $to);
        $this->repointPayloads($userId, $from, $to);
    }

    private function repointBudgets(int $userId, int $from, ?int $to): void
    {
        $lines = DB::table('budgets')->where('user_id', $userId)->where('category_id', $from)->orderBy('id')->get();

        foreach ($lines as $line) {
            if ($to === null) {
                DB::table('budgets')->where('id', $line->id)->update(['category_id' => null]);

                continue;
            }

            $existing = DB::table('budgets')
                ->where('user_id', $userId)
                ->where('category_id', $to)
                ->when(
                    $line->budget_plan_id !== null,
                    fn (Builder $query) => $query->where('budget_plan_id', $line->budget_plan_id),
                    fn (Builder $query) => $query->whereNull('budget_plan_id')->where('period', $line->period)->where('start_date', $line->start_date),
                )
                ->orderBy('id')
                ->first();

            if ($existing === null) {
                DB::table('budgets')->where('id', $line->id)->update(['category_id' => $to]);

                continue;
            }

            DB::table('budgets')->where('id', $existing->id)->update([
                'limit_amount' => $existing->limit_amount === null && $line->limit_amount === null
                    ? null
                    : (int) $existing->limit_amount + (int) $line->limit_amount,
                'rolled_over_amount' => (int) $existing->rolled_over_amount + (int) $line->rolled_over_amount,
            ]);
            DB::table('budgets')->where('id', $line->id)->delete();
        }
    }

    private function repointBudgetTag(int $userId, int $from): void
    {
        DB::table('user_category_budget_tags')->where('user_id', $userId)->where('category_id', $from)->delete();
    }

    private function repointProbabilities(int $userId, int $from, ?int $to): void
    {
        DB::table('payees')->where('user_id', $userId)->whereNotNull('probabilities')->chunkById(self::CHUNK, function (Collection $rows) use ($from, $to): void {
            foreach ($rows as $row) {
                $probabilities = $this->decode($row->probabilities);

                if (! array_key_exists($from, $probabilities)) {
                    continue;
                }

                $moved = $probabilities[$from];
                unset($probabilities[$from]);

                if ($to !== null) {
                    $probabilities[$to] = (is_numeric($probabilities[$to] ?? null) ? $probabilities[$to] : 0) + (is_numeric($moved) ? $moved : 0);
                }

                DB::table('payees')->where('id', $row->id)->update(['probabilities' => json_encode($probabilities, JSON_FORCE_OBJECT)]);
            }
        });
    }

    private function repointRules(int $userId, int $from, ?int $to): void
    {
        DB::table('user_rules')->where('user_id', $userId)->chunkById(self::CHUNK, function (Collection $rules) use ($from, $to): void {
            foreach ($rules as $rule) {
                $actions = $this->decode($rule->actions);
                $triggers = $this->decode($rule->triggers);

                if ($to !== null) {
                    $newActions = $this->remapActions($actions, $from, $to);
                    $newTriggers = array_map(
                        fn (mixed $trigger): mixed => $this->isCategoryTrigger($trigger, $from) ? [...$trigger, 'value' => $this->remapId($trigger['value'], $to)] : $trigger,
                        $triggers,
                    );

                    if ($newActions !== $actions || $newTriggers !== $triggers) {
                        DB::table('user_rules')->where('id', $rule->id)->update(['actions' => json_encode($newActions), 'triggers' => json_encode($newTriggers)]);
                    }

                    continue;
                }

                $keptActions = $this->stripActions($actions, $from);
                $keptTriggers = array_values(array_filter($triggers, fn (mixed $trigger): bool => ! $this->isPositiveCategoryTrigger($trigger, $from)));
                $lostTrigger = count($keptTriggers) !== count($triggers);

                if (count($keptActions) === count($actions) && ! $lostTrigger) {
                    continue;
                }

                $deactivate = $keptActions === [] || $keptTriggers === [] || ($lostTrigger && $rule->strict_mode);

                DB::table('user_rules')->where('id', $rule->id)->update([
                    'actions' => json_encode($keptActions, JSON_THROW_ON_ERROR),
                    'triggers' => json_encode($keptTriggers, JSON_THROW_ON_ERROR),
                    ...($deactivate ? ['is_active' => false] : []),
                ]);
            }
        });
    }

    private function repointPayloads(int $userId, int $from, ?int $to): void
    {
        $this->rewritePayloads(
            'analysis_suggestions',
            DB::table('analysis_suggestions')->where('user_id', $userId)->whereNotNull('payload'),
            $from,
            $to,
        );

        $this->rewritePayloads(
            'bnpl_order_events',
            DB::table('bnpl_order_events')
                ->whereIn('bnpl_order_id', fn (Builder $query) => $query->select('id')->from('bnpl_orders')->where('user_id', $userId))
                ->whereNotNull('payload'),
            $from,
            $to,
        );
    }

    private function rewritePayloads(string $table, Builder $query, int $from, ?int $to): void
    {
        $query->chunkById(self::CHUNK, function (Collection $rows) use ($table, $from, $to): void {
            foreach ($rows as $row) {
                $payload = $this->decode($row->payload);
                $rewritten = $payload;

                foreach (['category_id', 'previous_category_id'] as $key) {
                    if (array_key_exists($key, $rewritten) && $this->idMatches($rewritten[$key], $from)) {
                        $rewritten[$key] = $to === null ? null : $this->remapId($rewritten[$key], $to);
                    }
                }

                $emptied = false;

                if (isset($rewritten['actions']) && is_array($rewritten['actions'])) {
                    $rewritten['actions'] = $to === null ? $this->stripActions($rewritten['actions'], $from) : $this->remapActions($rewritten['actions'], $from, $to);
                    $emptied = $to === null && $rewritten['actions'] === [] && $payload['actions'] !== [];
                }

                if ($rewritten === $payload) {
                    continue;
                }

                $dismiss = $emptied && $table === 'analysis_suggestions' && $row->status === SuggestionStatus::Pending->value;

                DB::table($table)->where('id', $row->id)->update([
                    'payload' => json_encode($rewritten),
                    ...($dismiss ? ['status' => SuggestionStatus::Rejected->value, 'resolved_at' => now()->toDateTimeString()] : []),
                ]);
            }
        });
    }

    private function applyRecentPicks(User $user): void
    {
        $current = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail()->preferences->recentCategoryIds;
        $picks = $current;

        foreach ($this->moves as [$from, $to]) {
            $picks = array_map(fn (int $id): ?int => $id === $from ? $to : $id, $picks);
            $picks = array_values(array_unique(array_filter($picks, fn (?int $id): bool => $id !== null)));
        }

        $picks = array_slice($picks, 0, UserPreferences::RECENT_CATEGORY_LIMIT);

        if ($picks !== $current) {
            $user->updatePreferences(['recentCategoryIds' => $picks]);
        }
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws JsonException
     */
    private function decode(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<array-key, mixed>  $actions
     * @return array<array-key, mixed>
     */
    private function remapActions(array $actions, int $from, int $to): array
    {
        return array_map(
            fn (mixed $action): mixed => $this->isSetCategory($action, $from) ? [...$action, 'value' => $this->remapId($action['value'], $to)] : $action,
            $actions,
        );
    }

    /**
     * @param  array<array-key, mixed>  $actions
     * @return list<mixed>
     */
    private function stripActions(array $actions, int $from): array
    {
        return array_values(array_filter($actions, fn (mixed $action): bool => ! $this->isSetCategory($action, $from)));
    }

    /**
     * @phpstan-assert-if-true array{type: mixed, value: mixed} $action
     */
    private function isSetCategory(mixed $action, int $from): bool
    {
        return is_array($action)
            && ($action['type'] ?? null) === RuleActionType::SetCategory->value
            && $this->idMatches($action['value'] ?? null, $from);
    }

    /**
     * @phpstan-assert-if-true array{field: mixed, operator?: mixed, value: mixed} $trigger
     */
    private function isCategoryTrigger(mixed $trigger, int $from): bool
    {
        return is_array($trigger)
            && ($trigger['field'] ?? null) === RuleTriggerField::CategoryId->value
            && $this->idMatches($trigger['value'] ?? null, $from);
    }

    /**
     * @phpstan-assert-if-true array{field: mixed, operator?: mixed, value: mixed} $trigger
     */
    private function isPositiveCategoryTrigger(mixed $trigger, int $from): bool
    {
        return $this->isCategoryTrigger($trigger, $from)
            && in_array($trigger['operator'] ?? null, [RuleTriggerOperator::Equals->value, RuleTriggerOperator::Is->value], true);
    }

    private function idMatches(mixed $value, int $id): bool
    {
        return (is_int($value) && $value === $id) || (is_string($value) && ctype_digit($value) && (int) $value === $id);
    }

    private function remapId(mixed $value, int $to): int|string
    {
        return is_string($value) ? (string) $to : $to;
    }
}
