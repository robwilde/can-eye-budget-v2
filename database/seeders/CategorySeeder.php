<?php

declare(strict_types=1);

namespace Database\Seeders;

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
            ]);

            $this->seedChildren($parent, $spec['children']);
        }
    }

    /**
     * @return list<array{name: string, icon: string, children: list<string|array{name: string, icon?: string, children?: list<string>}>}>
     */
    private function definitions(): array
    {
        return [
            [
                'name' => 'Office',
                'icon' => 'bolt',
                'children' => [
                    ['name' => 'Online Service', 'children' => ['Apple']],
                    'Software',
                    'AI Apps',
                    'Newsletter',
                    ['name' => 'Training', 'children' => ['Subscription', 'Course']],
                    ['name' => 'Hardware', 'children' => ['Rentals']],
                    'Mobile App',
                    '3D Printing',
                    'Laptop',
                    'Tools',
                    'IoT',
                ],
            ],
            [
                'name' => 'Personal',
                'icon' => 'sparkles',
                'children' => [
                    'Health',
                    'Subscription',
                    ['name' => 'Finance', 'children' => ['Bank Fees']],
                    'Hunter',
                    'Pet',
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
                ],
            ],
            [
                'name' => 'Entertainment',
                'icon' => 'sparkles',
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
                'name' => 'Food',
                'icon' => 'shopping-cart',
                'children' => [
                    'Groceries',
                    'Restaurant',
                    'Quick Foods',
                ],
            ],
            [
                'name' => 'Bills',
                'icon' => 'activity',
                'children' => [
                    ['name' => 'Rent', 'icon' => 'house-heart'],
                    'Cleaning',
                    'Mobile',
                    'Internet',
                    'Electricity',
                    'Hotwater',
                    'Food',
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
                'name' => 'Loan',
                'icon' => 'building-library',
                'children' => [
                    'Motorcycle',
                    ['name' => 'Latitude', 'children' => ['Interest', 'Fees']],
                    'Shane',
                ],
            ],
            [
                'name' => 'Transport',
                'icon' => 'home',
                'children' => [
                    ['name' => 'Motorcycle', 'children' => ['Fuel']],
                    'Uber',
                    'Scooter',
                    'Tolls',
                    'Parking',
                    'Translink',
                ],
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
