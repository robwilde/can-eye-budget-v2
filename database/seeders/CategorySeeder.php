<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

final class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Office' => [
                'Online Service' => ['Apple'],
                'Software',
                'AI Apps',
                'Newsletter',
                'Training' => ['Subscription', 'Course'],
                'Hardware' => ['Rentals'],
                'Mobile App',
                '3D Printing',
                'Laptop',
                'Tools',
                'IoT',
            ],
            'Personal' => [
                'Health',
                'Subscription',
                'Finance' => ['Bank Fees'],
                'Hunter',
                'Pet',
                'Kitchen',
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
            'Entertainment' => [
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
            'Food' => [
                'Groceries',
                'Restaurant',
                'Quick Foods',
            ],
            'Bills' => [
                'Rent',
                'Cleaning',
                'Mobile',
                'Internet',
                'Electricity',
                'Hotwater',
                'Food',
            ],
            'Income' => [
                'Salary',
                'Client',
                'Medicare',
            ],
            'Transfer' => [
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
            'Loan' => [
                'Motorcycle',
                'Latitude' => ['Interest', 'Fees'],
                'Shane',
            ],
            'Transport' => [
                'Motorcycle' => ['Fuel'],
                'Uber',
                'Scooter',
                'Tolls',
                'Parking',
                'Translink',
            ],
        ];

        foreach ($categories as $parentName => $children) {
            $parent = Category::create(['name' => $parentName]);
            $this->seedChildren($parent, $children);
        }
    }

    /** @param array<int|string, string|list<string>> $children */
    private function seedChildren(Category $parent, array $children): void
    {
        foreach ($children as $key => $value) {
            if (is_string($key)) {
                $child = Category::create([
                    'name' => $key,
                    'parent_id' => $parent->id,
                ]);

                foreach ($value as $grandchildName) {
                    Category::create([
                        'name' => $grandchildName,
                        'parent_id' => $child->id,
                    ]);
                }
            } else {
                Category::create([
                    'name' => $value,
                    'parent_id' => $parent->id,
                ]);
            }
        }
    }
}
