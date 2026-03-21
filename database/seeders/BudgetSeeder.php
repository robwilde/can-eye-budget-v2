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
        $streaming = Category::where('name', 'Streaming')->whereNotNull('parent_id')->first();
        $insurance = Category::where('name', 'Insurance Premiums')->whereNotNull('parent_id')->first();
        $loanRepayments = Category::where('name', 'Loan Repayments')->whereNotNull('parent_id')->first();

        Budget::factory()->for($user)->create([
            'name' => 'Groceries',
            'limit_amount' => 60000,
            'category_id' => $groceries?->id,
            'start_date' => '2026-01-01',
        ]);

        Budget::factory()->for($user)->create([
            'name' => 'Streaming & Subscriptions',
            'limit_amount' => 10000,
            'category_id' => $streaming?->id,
            'start_date' => '2026-01-01',
        ]);

        Budget::factory()->for($user)->create([
            'name' => 'Insurance',
            'limit_amount' => 30000,
            'category_id' => $insurance?->id,
            'start_date' => '2026-01-01',
        ]);

        Budget::factory()->for($user)->create([
            'name' => 'Loan Repayments',
            'limit_amount' => 30000,
            'category_id' => $loanRepayments?->id,
            'start_date' => '2026-01-01',
        ]);
    }
}
