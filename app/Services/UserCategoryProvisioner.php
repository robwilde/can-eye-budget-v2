<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BudgetTag;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UserCategoryProvisioner
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
    ];

    /**
     * Gives the user the default groups and category tree. A user who already
     * owns categories is left as they are, so running it again changes nothing.
     */
    public function provision(User $user): void
    {
        DB::transaction(function () use ($user): void {
            if (Category::query()->where('user_id', $user->id)->exists()) {
                return;
            }

            $groups = $this->createGroups($user);

            foreach ($this->definitions() as $spec) {
                $root = Category::query()->create([
                    'user_id' => $user->id,
                    'name' => $spec['name'],
                    'icon' => $spec['icon'],
                    'budget_tag' => $spec['budget_tag'] ?? null,
                    'group_id' => $this->groupId($groups, self::ROOT_GROUPS[$spec['name']] ?? null),
                ]);

                $this->createChildren($user, $root, $spec['children'], $groups);
            }
        });
    }

    /**
     * @return array<string, int>
     */
    private function createGroups(User $user): array
    {
        $groups = [];

        foreach (self::GROUPS as $position => $spec) {
            $groups[$spec['name']] = CategoryGroup::query()->firstOrCreate(
                ['user_id' => $user->id, 'name' => $spec['name']],
                ['color' => $spec['color'], 'icon' => $spec['icon'], 'sort_order' => $position],
            )->id;
        }

        return $groups;
    }

    /**
     * @param  array<string, int>  $groups
     */
    private function groupId(array $groups, ?string $groupName): ?int
    {
        return $groupName === null ? null : ($groups[$groupName] ?? null);
    }

    /**
     * @param  array<string, int>  $groups
     */
    private function childGroupId(array $groups, Category $root, string $childName): ?int
    {
        foreach (self::CHILD_GROUPS as $override) {
            if ($override['root'] === $root->name && $override['name'] === $childName) {
                return $this->groupId($groups, $override['group']);
            }
        }

        return null;
    }

    /**
     * @param  list<string|array{name: string, icon?: string, children?: list<string>}>  $children
     * @param  array<string, int>  $groups
     */
    private function createChildren(User $user, Category $parent, array $children, array $groups): void
    {
        foreach ($children as $child) {
            $name = is_string($child) ? $child : $child['name'];

            $node = Category::query()->create([
                'user_id' => $user->id,
                'name' => $name,
                'parent_id' => $parent->id,
                'icon' => is_string($child) ? null : ($child['icon'] ?? null),
                'group_id' => $parent->parent_id === null ? $this->childGroupId($groups, $parent, $name) : null,
            ]);

            if (is_string($child)) {
                continue;
            }

            foreach ($child['children'] ?? [] as $grandchildName) {
                Category::query()->create([
                    'user_id' => $user->id,
                    'name' => $grandchildName,
                    'parent_id' => $node->id,
                ]);
            }
        }
    }

    /**
     * @return list<array{name: string, icon: string, budget_tag?: BudgetTag, children: list<string|array{name: string, icon?: string, children?: list<string>}>}>
     */
    private function definitions(): array
    {
        return [
            [
                'name' => 'Housing & Utilities',
                'icon' => 'home',
                'budget_tag' => BudgetTag::Needs,
                'children' => [
                    ['name' => 'Rent', 'icon' => 'house-heart'],
                    'Electricity',
                    'Hotwater',
                    'Internet',
                    'Mobile',
                    'Cleaning',
                ],
            ],
            [
                'name' => 'Groceries',
                'icon' => 'shopping-cart',
                'budget_tag' => BudgetTag::Needs,
                'children' => [],
            ],
            [
                'name' => 'Transport',
                'icon' => 'home',
                'budget_tag' => BudgetTag::Needs,
                'children' => [
                    ['name' => 'Motorcycle', 'children' => ['Fuel']],
                    'Uber',
                    'Scooter',
                    'Tolls',
                    'Parking',
                    'Translink',
                ],
            ],
            [
                'name' => 'Health',
                'icon' => 'activity',
                'budget_tag' => BudgetTag::Needs,
                'children' => [],
            ],
            [
                'name' => 'Insurance',
                'icon' => 'shield-check',
                'budget_tag' => BudgetTag::Needs,
                'children' => [],
            ],
            [
                'name' => 'Software & Online Services',
                'icon' => 'bolt',
                'budget_tag' => BudgetTag::Needs,
                'children' => [
                    ['name' => 'Online Service', 'children' => ['Apple']],
                    'Software',
                    'AI Apps',
                    'Mobile App',
                ],
            ],
            [
                'name' => 'Work Equipment',
                'icon' => 'wrench-screwdriver',
                'budget_tag' => BudgetTag::Wants,
                'children' => [
                    ['name' => 'Hardware', 'children' => ['Rentals']],
                    '3D Printing',
                    'IoT',
                    'Laptop',
                    'Tools',
                ],
            ],
            [
                'name' => 'Bank Fees & Finance Services',
                'icon' => 'building-library',
                'budget_tag' => BudgetTag::Needs,
                'children' => [
                    'Bank Fees',
                ],
            ],
            [
                'name' => 'Loans & Debt Repayment',
                'icon' => 'building-library',
                'budget_tag' => BudgetTag::Savings,
                'children' => [
                    'Motorcycle',
                    ['name' => 'Latitude', 'children' => ['Interest', 'Fees']],
                    'Shane',
                ],
            ],
            [
                'name' => 'Eating Out',
                'icon' => 'coffee',
                'budget_tag' => BudgetTag::Wants,
                'children' => [
                    'Restaurant',
                    'Quick Foods',
                ],
            ],
            [
                'name' => 'Learning & Reading',
                'icon' => 'book-open-text',
                'budget_tag' => BudgetTag::Wants,
                'children' => [
                    'Newsletter',
                    ['name' => 'Training', 'children' => ['Subscription', 'Course']],
                ],
            ],
            [
                'name' => 'Entertainment',
                'icon' => 'sparkles',
                'budget_tag' => BudgetTag::Wants,
                'children' => [
                    'Streaming',
                    'Patreon',
                    'Adult',
                    'Twitch',
                    'Gaming',
                    'Apps',
                    'Alcohol',
                    'Event',
                    'VR',
                ],
            ],
            [
                'name' => 'Pets',
                'icon' => 'house-heart',
                'budget_tag' => BudgetTag::Wants,
                'children' => [],
            ],
            [
                'name' => 'Personal & Shopping',
                'icon' => 'sparkles',
                'budget_tag' => BudgetTag::Wants,
                'children' => [
                    'Subscription',
                    'Hunter',
                    ['name' => 'Kitchen', 'icon' => 'coffee'],
                    'Clothes',
                    'Gifts',
                    'Grooming',
                    'Beddings',
                    'Bathroom',
                    'Holiday',
                    'Plants',
                    'Fines',
                    'Charity',
                    'Job Hunting',
                ],
            ],
            [
                'name' => 'Income',
                'icon' => 'arrow-trending-up',
                'children' => [
                    'Salary',
                    'Client',
                    'Medicare',
                ],
            ],
            [
                'name' => 'Transfer',
                'icon' => 'building-library',
                'children' => [
                    'Optimus to Spaceship',
                    'Optimus to CC',
                    'Optimus to uBank',
                    'FairGo Finance',
                    'uBank to uSavings',
                    'Optimus to Latitude',
                    'uBank to Optimus',
                    'Optimus to Cash',
                    'uSavings to uBank',
                ],
            ],
            [
                'name' => 'Balance',
                'icon' => 'building-library',
                'children' => [],
            ],
        ];
    }
}
