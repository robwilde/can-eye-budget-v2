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
            'Groceries' => ['Supermarkets', 'Specialty Food'],
            'Transport' => ['Fuel', 'Public Transport', 'Parking', 'Rideshare'],
            'Utilities' => ['Electricity', 'Gas', 'Water', 'Internet', 'Phone'],
            'Entertainment' => ['Streaming', 'Cinema', 'Events'],
            'Dining' => ['Restaurants', 'Takeaway', 'Coffee'],
            'Healthcare' => ['Pharmacy', 'Doctor', 'Dental'],
            'Shopping' => ['Clothing', 'Electronics', 'Home & Garden'],
            'Education' => ['Tuition', 'Books', 'Courses'],
            'Housing' => ['Rent', 'Mortgage', 'Insurance'],
            'Personal' => ['Fitness', 'Beauty', 'Subscriptions'],
            'Income' => ['Salary', 'Freelance', 'Interest'],
            'Transfers' => [],
        ];

        foreach ($categories as $parentName => $children) {
            $parent = Category::create(['name' => $parentName]);

            foreach ($children as $childName) {
                Category::create([
                    'name' => $childName,
                    'parent_id' => $parent->id,
                ]);
            }
        }
    }
}
