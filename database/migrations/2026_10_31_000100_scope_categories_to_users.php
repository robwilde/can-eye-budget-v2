<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives every user their own category groups and category tree.
 *
 * With no users the global rows are deleted. Otherwise every user except the
 * lowest-id user gets a clone of the global groups and tree, and that user's
 * references are rewritten onto the clone. The global rows then become the
 * lowest-id user's own, so a single-user install rewrites no ledger rows.
 *
 * Each user is handled in one transaction and a user who already owns
 * categories is skipped, so a run that failed part way can be restarted. The
 * global rows stay in place until every clone exists.
 *
 * Irreversible by design: collapsing per-user trees back into one would mean
 * choosing one user's edits over the others', so down() refuses to run.
 */
return new class extends Migration
{
    private const int CHUNK = 200;

    public function up(): void
    {
        $userIds = DB::table('users')->orderBy('id')->pluck('id')->map(intval(...))->all();

        if ($userIds === []) {
            DB::transaction(function (): void {
                DB::table('categories')->whereNull('user_id')->delete();
                DB::table('category_groups')->whereNull('user_id')->delete();
            });

            return;
        }

        $owner = array_shift($userIds);
        $groups = DB::table('category_groups')->whereNull('user_id')->orderBy('sort_order')->orderBy('id')->get()->all();
        $categories = $this->parentsFirst(DB::table('categories')->whereNull('user_id')->orderBy('id')->get()->all());

        foreach ($userIds as $userId) {
            if ($groups === [] && $categories === []) {
                break;
            }

            if (DB::table('categories')->where('user_id', $userId)->exists()) {
                continue;
            }

            DB::transaction(function () use ($userId, $groups, $categories): void {
                $this->cloneFor($userId, $groups, $categories);
            });
        }

        DB::transaction(function () use ($owner): void {
            DB::table('category_groups')->whereNull('user_id')->update(['user_id' => $owner]);
            DB::table('categories')->whereNull('user_id')->update(['user_id' => $owner]);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Scoping categories to users cannot be rolled back; restore a backup instead.');
    }

    /**
     * @param  list<object>  $categories
     * @return list<object>
     */
    private function parentsFirst(array $categories): array
    {
        $ordered = [];
        $placed = [];
        $remaining = $categories;

        while ($remaining !== []) {
            $next = [];

            foreach ($remaining as $category) {
                if ($category->parent_id === null || isset($placed[(int) $category->parent_id])) {
                    $ordered[] = $category;
                    $placed[(int) $category->id] = true;

                    continue;
                }

                $next[] = $category;
            }

            if (count($next) === count($remaining)) {
                throw new RuntimeException('Category tree has a parent that is missing or forms a cycle.');
            }

            $remaining = $next;
        }

        return $ordered;
    }

    /**
     * @param  list<object>  $groups
     * @param  list<object>  $categories
     */
    private function cloneFor(int $userId, array $groups, array $categories): void
    {
        $groupMap = [];

        foreach ($groups as $group) {
            $groupMap[(int) $group->id] = (int) DB::table('category_groups')->insertGetId([
                'user_id' => $userId,
                'name' => $group->name,
                'color' => $group->color,
                'icon' => $group->icon,
                'sort_order' => $group->sort_order,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $categoryMap = [];

        foreach ($categories as $category) {
            $categoryMap[(int) $category->id] = (int) DB::table('categories')->insertGetId([
                'user_id' => $userId,
                'name' => $category->name,
                'parent_id' => $category->parent_id === null ? null : $categoryMap[(int) $category->parent_id],
                'group_id' => $category->group_id === null ? null : ($groupMap[(int) $category->group_id] ?? null),
                'budget_tag' => $category->budget_tag,
                'anzsic_division' => $category->anzsic_division,
                'anzsic_subdivision' => $category->anzsic_subdivision,
                'anzsic_group' => $category->anzsic_group,
                'anzsic_class' => $category->anzsic_class,
                'icon' => $category->icon,
                'color' => $category->color,
                'is_hidden' => $category->is_hidden,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->rewriteColumns($userId, $categoryMap);
        $this->rewriteJson($userId, $categoryMap);
    }

    /**
     * @param  array<int, int>  $map
     */
    private function rewriteColumns(int $userId, array $map): void
    {
        foreach ($map as $old => $new) {
            foreach (['transactions', 'budgets', 'planned_transactions', 'bnpl_orders'] as $table) {
                DB::table($table)->where('user_id', $userId)->where('category_id', $old)->update(['category_id' => $new]);
            }

            DB::table('transaction_splits')
                ->where('category_id', $old)
                ->whereIn('transaction_id', fn ($query) => $query->select('id')->from('transactions')->where('user_id', $userId))
                ->update(['category_id' => $new]);

            DB::table('payees')->where('user_id', $userId)->where('suggested_category_id', $old)->update(['suggested_category_id' => $new]);
            DB::table('payees')->where('user_id', $userId)->where('confirmed_category_id', $old)->update(['confirmed_category_id' => $new]);
            DB::table('user_category_budget_tags')->where('user_id', $userId)->where('category_id', $old)->update(['category_id' => $new]);
        }
    }

    /**
     * @param  array<int, int>  $map
     */
    private function rewriteJson(int $userId, array $map): void
    {
        DB::table('user_rules')->where('user_id', $userId)->chunkById(self::CHUNK, function ($rules) use ($map): void {
            foreach ($rules as $rule) {
                $actions = $this->decode($rule->actions);
                $triggers = $this->decode($rule->triggers);
                $newActions = $this->remapActions($actions, $map);
                $newTriggers = array_map(fn (mixed $trigger): mixed => is_array($trigger) && ($trigger['field'] ?? null) === 'category_id' && array_key_exists('value', $trigger)
                    ? [...$trigger, 'value' => $this->remapId($trigger['value'], $map)]
                    : $trigger, $triggers);

                if ($newActions !== $actions || $newTriggers !== $triggers) {
                    DB::table('user_rules')->where('id', $rule->id)->update([
                        'actions' => json_encode($newActions),
                        'triggers' => json_encode($newTriggers),
                    ]);
                }
            }
        });

        DB::table('analysis_suggestions')->where('user_id', $userId)->whereNotNull('payload')->chunkById(self::CHUNK, function ($rows) use ($map): void {
            foreach ($rows as $row) {
                $this->rewritePayload('analysis_suggestions', $row, $map);
            }
        });

        DB::table('bnpl_order_events')
            ->whereIn('bnpl_order_id', fn ($query) => $query->select('id')->from('bnpl_orders')->where('user_id', $userId))
            ->whereNotNull('payload')
            ->chunkById(self::CHUNK, function ($rows) use ($map): void {
                foreach ($rows as $row) {
                    $this->rewritePayload('bnpl_order_events', $row, $map);
                }
            });

        DB::table('payees')->where('user_id', $userId)->whereNotNull('probabilities')->chunkById(self::CHUNK, function ($rows) use ($map): void {
            foreach ($rows as $row) {
                $probabilities = $this->decode($row->probabilities);
                $remapped = [];

                foreach ($probabilities as $categoryId => $probability) {
                    $remapped[$map[(int) $categoryId] ?? $categoryId] = $probability;
                }

                if ($remapped !== $probabilities) {
                    DB::table('payees')->where('id', $row->id)->update(['probabilities' => json_encode($remapped, JSON_FORCE_OBJECT)]);
                }
            }
        });
    }

    /**
     * @param  array<int, int>  $map
     */
    private function rewritePayload(string $table, object $row, array $map): void
    {
        $payload = json_decode((string) $row->payload, true);

        if (! is_array($payload)) {
            return;
        }

        $remapped = $payload;

        foreach (['category_id', 'previous_category_id'] as $key) {
            if (array_key_exists($key, $remapped)) {
                $remapped[$key] = $this->remapId($remapped[$key], $map);
            }
        }

        if (isset($remapped['actions']) && is_array($remapped['actions'])) {
            $remapped['actions'] = $this->remapActions($remapped['actions'], $map);
        }

        if ($remapped !== $payload) {
            DB::table($table)->where('id', $row->id)->update(['payload' => json_encode($remapped)]);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<array-key, mixed>  $actions
     * @param  array<int, int>  $map
     * @return array<array-key, mixed>
     */
    private function remapActions(array $actions, array $map): array
    {
        return array_map(fn (mixed $action): mixed => is_array($action) && ($action['type'] ?? null) === 'set_category' && array_key_exists('value', $action)
            ? [...$action, 'value' => $this->remapId($action['value'], $map)]
            : $action, $actions);
    }

    /**
     * @param  array<int, int>  $map
     */
    private function remapId(mixed $value, array $map): mixed
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return $value;
        }

        $new = $map[(int) $value] ?? null;

        if ($new === null) {
            return $value;
        }

        return is_string($value) ? (string) $new : $new;
    }
};
