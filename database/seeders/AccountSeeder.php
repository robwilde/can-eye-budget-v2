<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Seeder;

final class AccountSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'test@example.com')->firstOrFail();

        Account::factory()->for($user)->withBasiq()->create([
            'name' => 'Everyday Transaction',
            'institution' => 'Commonwealth Bank',
        ]);

        Account::factory()->savings()->for($user)->withBasiq()->create([
            'name' => 'Goal Saver',
            'institution' => 'Commonwealth Bank',
        ]);

        Account::factory()->creditCard()->for($user)->withBasiq()->create([
            'name' => 'Low Rate Visa',
            'institution' => 'Westpac',
        ]);

        Account::factory()->mortgage()->for($user)->withBasiq()->create([
            'name' => 'Home Loan Variable',
            'institution' => 'ANZ',
        ]);

        Account::factory()->investment()->for($user)->create([
            'name' => 'Share Portfolio',
            'institution' => 'Macquarie Bank',
        ]);

        Account::factory()->closed()->for($user)->create([
            'name' => 'Old Savings',
            'institution' => 'ING',
        ]);
    }
}
