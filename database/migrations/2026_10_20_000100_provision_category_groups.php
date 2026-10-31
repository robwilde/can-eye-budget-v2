<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the global category groups and assigns them to the matching
 * categories, as CategoryGroupProvisioner did while categories were global.
 * The group maps and logic are copied here so the per-user provisioner that
 * replaces it cannot alter what this migration does against global rows.
 */
return new class extends Migration
{
    private const string SAVINGS_GROUP = 'Savings & Investments';

    /**
     * @var list<array{name: string, color: string, icon: string}>
     */
    private const array GROUPS = [
        ['name' => 'Bills', 'color' => '#db5a54', 'icon' => 'receipt-percent'],
        ['name' => 'Food', 'color' => '#f97316', 'icon' => 'shopping-bag'],
        ['name' => 'Office', 'color' => '#6471eb', 'icon' => 'building-office'],
        ['name' => 'Training', 'color' => '#0284c7', 'icon' => 'academic-cap'],
        ['name' => 'Personal', 'color' => '#14b8a6', 'icon' => 'user'],
        ['name' => 'Health', 'color' => '#4da568', 'icon' => 'activity'],
        ['name' => 'Insurance', 'color' => '#0369a1', 'icon' => 'shield-check'],
        ['name' => 'Entertainment', 'color' => '#a855f7', 'icon' => 'sparkles'],
        ['name' => 'Transport', 'color' => '#0ea5e9', 'icon' => 'truck'],
        ['name' => 'Loan', 'color' => '#e11d48', 'icon' => 'credit-card'],
        ['name' => 'Income', 'color' => '#22c55e', 'icon' => 'arrow-trending-up'],
        ['name' => 'Transfer', 'color' => '#444ce7', 'icon' => 'arrows-right-left'],
        ['name' => self::SAVINGS_GROUP, 'color' => '#059669', 'icon' => 'banknotes'],
    ];

    /**
     * @var array<string, string>
     */
    private const array ROOT_GROUPS = [
        'Housing & Utilities' => 'Bills',
        'Groceries' => 'Food',
        'Eating Out' => 'Food',
        'Software & Online Services' => 'Office',
        'Work Equipment' => 'Office',
        'Learning & Reading' => 'Training',
        'Personal & Shopping' => 'Personal',
        'Pets' => 'Personal',
        'Bank Fees & Finance Services' => 'Personal',
        'Health' => 'Health',
        'Insurance' => 'Insurance',
        'Entertainment' => 'Entertainment',
        'Transport' => 'Transport',
        'Loans & Debt Repayment' => 'Loan',
        'Income' => 'Income',
        'Transfer' => 'Transfer',
    ];

    /**
     * @var list<array{root: string, name: string, group: string}>
     */
    private const array CHILD_GROUPS = [
        ['root' => 'Transfer', 'name' => 'Optimus to Spaceship', 'group' => self::SAVINGS_GROUP],
        ['root' => 'Transfer', 'name' => 'uBank to uSavings', 'group' => self::SAVINGS_GROUP],
        ['root' => 'Transfer', 'name' => 'Optimus to Savings', 'group' => self::SAVINGS_GROUP],
    ];

    public function up(): void
    {
        if (DB::table('categories')->doesntExist()) {
            return;
        }

        DB::transaction(function (): void {
            $groups = [];

            foreach (self::GROUPS as $position => $spec) {
                $id = DB::table('category_groups')->where('name', $spec['name'])->value('id');

                $groups[$spec['name']] = $id ?? DB::table('category_groups')->insertGetId([
                    'name' => $spec['name'],
                    'color' => $spec['color'],
                    'icon' => $spec['icon'],
                    'sort_order' => $position,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach (self::ROOT_GROUPS as $rootName => $groupName) {
                DB::table('categories')
                    ->whereNull('parent_id')
                    ->where('name', $rootName)
                    ->whereNull('group_id')
                    ->update(['group_id' => $groups[$groupName]]);
            }

            foreach (self::CHILD_GROUPS as $override) {
                $rootIds = DB::table('categories')
                    ->whereNull('parent_id')
                    ->where('name', $override['root'])
                    ->pluck('id');

                DB::table('categories')
                    ->whereIn('parent_id', $rootIds)
                    ->where('name', $override['name'])
                    ->whereNull('group_id')
                    ->update(['group_id' => $groups[$override['group']]]);
            }
        });
    }

    public function down(): void {}
};
