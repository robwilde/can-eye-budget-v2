<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Seeder;

final class BudgetSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'test@example.com')->firstOrFail();

        $groceries = Category::where('name', 'Groceries')->whereNotNull('parent_id')->first();
        $fuel = Category::where('name', 'Fuel')->whereNotNull('parent_id')->first();
        $takeaway = Category::where('name', 'Takeaway')->whereNotNull('parent_id')->first();
        $electricity = Category::where('name', 'Electricity')->whereNotNull('parent_id')->first();

        Budget::factory()->for($user)->create([
            'name' => 'Monthly Groceries',
            'limit_amount' => 80000,
            'category_id' => $groceries?->id,
            'start_date' => '2026-03-01',
        ]);

        Budget::factory()->for($user)->create([
            'name' => 'Fuel Budget',
            'limit_amount' => 30000,
            'category_id' => $fuel?->id,
            'start_date' => '2026-03-01',
        ]);

        Budget::factory()->for($user)->create([
            'name' => 'Takeaway Limit',
            'limit_amount' => 15000,
            'category_id' => $takeaway?->id,
            'start_date' => '2026-03-01',
        ]);

        Budget::factory()->for($user)->create([
            'name' => 'Electricity',
            'limit_amount' => 25000,
            'category_id' => $electricity?->id,
            'start_date' => '2026-03-01',
        ]);
    }
}
