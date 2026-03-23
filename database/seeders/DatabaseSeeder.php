<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('H@rd24G$t'),
            'basiq_user_id' => '3470f92c-54d1-4a68-a767-1d031d340d06',
        ]);

        $this->call(CategorySeeder::class);
        $this->call(BudgetSeeder::class);
    }
}
