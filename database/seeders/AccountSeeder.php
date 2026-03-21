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
            'name' => 'Optimus',
            'institution' => 'Westpac',
            'balance' => 243080,
        ]);
    }
}
