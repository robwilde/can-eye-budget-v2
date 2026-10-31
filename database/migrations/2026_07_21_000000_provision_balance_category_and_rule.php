<?php

declare(strict_types=1);

use App\Models\UserRule;
use App\Models\UserRuleGroup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Provisions the global Balance category and every user's month-end balance
 * rule, as MonthEndBalanceRuleProvisioner did while categories were global.
 * The logic is copied here so later per-user changes to that service cannot
 * alter what this migration does against global rows.
 */
return new class extends Migration
{
    private const string CATEGORY_NAME = 'Balance';

    private const string MATCH_VALUE = 'Month End Balance';

    private const string GROUP_NAME = 'Auto-categorisation';

    private const string RULE_NAME = 'Categorise month-end balance';

    public function up(): void
    {
        if (DB::table('categories')->doesntExist()) {
            return;
        }

        $categoryId = DB::table('categories')
            ->whereNull('parent_id')
            ->where('name', self::CATEGORY_NAME)
            ->orderBy('id')
            ->value('id');

        if ($categoryId === null) {
            $categoryId = DB::table('categories')->insertGetId([
                'name' => self::CATEGORY_NAME,
                'parent_id' => null,
                'icon' => 'building-library',
                'is_hidden' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('categories')->where('id', $categoryId)->update(['is_hidden' => false]);

        DB::table('users')->orderBy('id')->select('id')->chunkById(100, function ($users) use ($categoryId): void {
            foreach ($users as $user) {
                $this->provision((int) $user->id, (int) $categoryId);
            }
        });
    }

    public function down(): void {}

    private function provision(int $userId, int $categoryId): void
    {
        $group = UserRuleGroup::query()->firstOrCreate(
            ['user_id' => $userId, 'name' => self::GROUP_NAME],
            [
                'order' => (int) UserRuleGroup::query()->where('user_id', $userId)->max('order') + 1,
                'is_active' => true,
                'stop_processing' => false,
            ],
        );

        $triggers = [
            ['field' => 'description', 'operator' => 'contains', 'value' => self::MATCH_VALUE],
            ['field' => 'category_id', 'operator' => 'is_empty', 'value' => ''],
        ];

        $actions = [['type' => 'set_category', 'value' => (string) $categoryId]];

        $rule = UserRule::query()->firstOrCreate(
            ['user_id' => $userId, 'user_rule_group_id' => $group->id, 'name' => self::RULE_NAME],
            [
                'triggers' => $triggers,
                'actions' => $actions,
                'strict_mode' => true,
                'is_auto_apply' => true,
                'is_active' => true,
                'order' => (int) UserRule::query()->where('user_rule_group_id', $group->id)->max('order') + 1,
            ],
        );

        if (! $rule->wasRecentlyCreated) {
            if ($rule->triggers !== $triggers || $rule->actions !== $actions) {
                $rule->update(['triggers' => $triggers, 'actions' => $actions]);
            }

            return;
        }

        if ($group->is_active) {
            $this->applyToExisting($userId, $categoryId);
        }
    }

    private function applyToExisting(int $userId, int $categoryId): void
    {
        DB::table('transactions')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->whereNull('category_id')
            ->whereNull('transfer_pair_id')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('transactions as children')
                ->whereColumn('children.parent_transaction_id', 'transactions.id')
                ->whereNull('children.deleted_at'))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('transaction_splits')
                ->whereColumn('transaction_splits.transaction_id', 'transactions.id'))
            ->select(['transactions.id', 'transactions.description'])
            ->chunkById(200, function ($rows) use ($categoryId): void {
                $ids = collect($rows)
                    ->filter(fn (object $row): bool => mb_stripos((string) $row->description, self::MATCH_VALUE) !== false)
                    ->pluck('id')
                    ->all();

                if ($ids === []) {
                    return;
                }

                DB::table('transactions')->whereIn('id', $ids)->update([
                    'category_id' => $categoryId,
                    'updated_at' => now(),
                ]);
            }, 'transactions.id', 'id');
    }
};
