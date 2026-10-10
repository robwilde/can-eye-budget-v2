<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BudgetTag;
use App\Models\Category;
use Illuminate\Database\Seeder;

final class CategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->definitions() as $spec) {
            $parent = Category::create([
                'name' => $spec['name'],
                'icon' => $spec['icon'],
                'budget_tag' => $spec['budget_tag'] ?? null,
            ]);

            $this->seedChildren($parent, $spec['children']);
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

    /** @param list<string|array{name: string, icon?: string, children?: list<string>}> $children */
    private function seedChildren(Category $parent, array $children): void
    {
        foreach ($children as $child) {
            if (is_string($child)) {
                Category::create([
                    'name' => $child,
                    'parent_id' => $parent->id,
                ]);

                continue;
            }

            $node = Category::create([
                'name' => $child['name'],
                'parent_id' => $parent->id,
                'icon' => $child['icon'] ?? null,
            ]);

            foreach ($child['children'] ?? [] as $grandchildName) {
                Category::create([
                    'name' => $grandchildName,
                    'parent_id' => $node->id,
                ]);
            }
        }
    }
}
