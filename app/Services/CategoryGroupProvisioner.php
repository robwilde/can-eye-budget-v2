<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\CategoryGroup;

final class CategoryGroupProvisioner
{
    public const string SAVINGS_GROUP = 'Savings & Investments';

    /**
     * @var list<array{name: string, color: string, icon: string}>
     */
    public const array GROUPS = [
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
    public const array ROOT_GROUPS = [
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
    public const array CHILD_GROUPS = [
        ['root' => 'Transfer', 'name' => 'Optimus to Spaceship', 'group' => self::SAVINGS_GROUP],
        ['root' => 'Transfer', 'name' => 'uBank to uSavings', 'group' => self::SAVINGS_GROUP],
        ['root' => 'Transfer', 'name' => 'Optimus to Savings', 'group' => self::SAVINGS_GROUP],
    ];

    /**
     * Seeds the groups and assigns them to the matching categories. Existing
     * groups are kept as they are and a category that already has a group is
     * never reassigned, so running it again changes nothing.
     */
    public function provision(): void
    {
        Category::query()->getConnection()->transaction(function (): void {
            $groups = [];

            foreach (self::GROUPS as $position => $spec) {
                $groups[$spec['name']] = CategoryGroup::query()->firstOrCreate(
                    ['name' => $spec['name']],
                    ['color' => $spec['color'], 'icon' => $spec['icon'], 'sort_order' => $position],
                );
            }

            foreach (self::ROOT_GROUPS as $rootName => $groupName) {
                Category::query()
                    ->whereNull('parent_id')
                    ->where('name', $rootName)
                    ->whereNull('group_id')
                    ->update(['group_id' => $groups[$groupName]->id]);
            }

            foreach (self::CHILD_GROUPS as $override) {
                $rootIds = Category::query()
                    ->whereNull('parent_id')
                    ->where('name', $override['root'])
                    ->pluck('id');

                Category::query()
                    ->whereIn('parent_id', $rootIds)
                    ->where('name', $override['name'])
                    ->whereNull('group_id')
                    ->update(['group_id' => $groups[$override['group']]->id]);
            }
        });
    }
}
