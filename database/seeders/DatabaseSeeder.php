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

        $sandboxUsers = [
            ['name' => 'Max Wentworth-Smith', 'email' => 'maxsmith@micr0soft.com', 'password' => 'whislter'],
            ['name' => 'Whistler Smith', 'email' => 'whistler@h0tmail.com', 'password' => 'ShowBox'],
            ['name' => 'Gilfoyle Bertram', 'email' => 'gilfoyle@mgail.com', 'password' => 'PiedPiper'],
            ['name' => 'Gavin Belson', 'email' => 'gavinbelson@h0tmail.com', 'password' => 'hooli2016'],
            ['name' => 'Jared Dunn', 'email' => 'Jared.D@h0tmail.com', 'password' => 'django'],
            ['name' => 'Richard Birtles', 'email' => 'r.birtles@tetlerjones.c0m.au', 'password' => 'tabsnotspaces'],
            ['name' => 'Laurie Bream', 'email' => 'business@manlyaccountants.com.au', 'password' => 'business2024'],
            ['name' => 'Ash Mann', 'email' => 'ashmann@gamil.com', 'password' => 'hooli2024'],
        ];

        foreach ($sandboxUsers as $userData) {
            User::factory()->create([
                'name' => $userData['name'],
                'email' => $userData['email'],
                'password' => Hash::make($userData['password']),
                'email_verified_at' => now(),
            ]);
        }
    }
}
