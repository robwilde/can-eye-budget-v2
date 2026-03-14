<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

final class TransactionSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'test@example.com')->firstOrFail();
        $accounts = $user->accounts;

        if ($accounts->isEmpty()) {
            throw new RuntimeException('TransactionSeeder requires accounts. Run AccountSeeder first.');
        }

        $categories = Category::whereNull('parent_id')->get();

        $transactionAccount = $accounts->firstWhere('name', 'Everyday Transaction') ?? $accounts->first();
        $savingsAccount = $accounts->firstWhere('name', 'Goal Saver') ?? $transactionAccount;
        $creditCardAccount = $accounts->firstWhere('name', 'Low Rate Visa') ?? $transactionAccount;

        $groceries = $categories->firstWhere('name', 'Groceries');
        $transport = $categories->firstWhere('name', 'Transport');
        $dining = $categories->firstWhere('name', 'Dining');
        $utilities = $categories->firstWhere('name', 'Utilities');
        $income = $categories->firstWhere('name', 'Income');

        Transaction::factory()->for($user)->for($transactionAccount)->fromBasiq()->create([
            'description' => 'WOOLWORTHS 1234 SYDNEY',
            'clean_description' => 'Woolworths Sydney',
            'amount' => 8545,
            'post_date' => '2026-03-10',
            'category_id' => $groceries?->id,
        ]);

        Transaction::factory()->for($user)->for($transactionAccount)->fromBasiq()->create([
            'description' => 'COLES SUPERMARKET MELBOURNE',
            'clean_description' => 'Coles Melbourne',
            'amount' => 12340,
            'post_date' => '2026-03-09',
            'category_id' => $groceries?->id,
        ]);

        Transaction::factory()->for($user)->for($transactionAccount)->fromBasiq()->create([
            'description' => 'BP SERVICE STATION GEELONG',
            'clean_description' => 'BP Geelong',
            'amount' => 9800,
            'post_date' => '2026-03-08',
            'category_id' => $transport?->id,
        ]);

        Transaction::factory()->for($user)->for($transactionAccount)->create([
            'description' => 'UBER TRIP SYDNEY',
            'amount' => 2350,
            'post_date' => '2026-03-07',
            'category_id' => $transport?->id,
        ]);

        Transaction::factory()->for($user)->for($transactionAccount)->fromBasiq()->create([
            'description' => 'MCDONALDS PARRAMATTA',
            'clean_description' => 'McDonalds Parramatta',
            'amount' => 1495,
            'post_date' => '2026-03-06',
            'category_id' => $dining?->id,
        ]);

        Transaction::factory()->for($user)->for($transactionAccount)->create([
            'description' => 'ORIGIN ENERGY BILL',
            'amount' => 18500,
            'post_date' => '2026-03-05',
            'category_id' => $utilities?->id,
        ]);

        Transaction::factory()->credit()->for($user)->for($transactionAccount)->fromBasiq()->create([
            'description' => 'SALARY PAYMENT ACME PTY LTD',
            'clean_description' => 'Salary - Acme Pty Ltd',
            'amount' => 450000,
            'post_date' => '2026-03-01',
            'category_id' => $income?->id,
        ]);

        Transaction::factory()->credit()->for($user)->for($transactionAccount)->create([
            'description' => 'TRANSFER FROM SAVINGS',
            'amount' => 100000,
            'post_date' => '2026-03-03',
        ]);

        Transaction::factory()->for($user)->for($transactionAccount)->pending()->create([
            'description' => 'BUNNINGS WAREHOUSE RYDE',
            'amount' => 5670,
            'post_date' => '2026-03-13',
        ]);

        Transaction::factory()->for($user)->for($transactionAccount)->pending()->fromBasiq()->create([
            'description' => 'JB HI-FI ONLINE',
            'clean_description' => 'JB Hi-Fi Online',
            'amount' => 29900,
            'post_date' => '2026-03-14',
        ]);

        Transaction::factory()->for($user)->for($creditCardAccount)->fromBasiq()->create([
            'description' => 'AMAZON AU MARKETPLACE',
            'clean_description' => 'Amazon Australia',
            'amount' => 4999,
            'post_date' => '2026-03-11',
        ]);

        Transaction::factory()->for($user)->for($creditCardAccount)->create([
            'description' => 'NETFLIX.COM',
            'amount' => 1699,
            'post_date' => '2026-03-02',
        ]);

        Transaction::factory()->for($user)->for($creditCardAccount)->fromBasiq()->create([
            'description' => 'SPOTIFY P12345678',
            'clean_description' => 'Spotify Subscription',
            'amount' => 1199,
            'post_date' => '2026-03-02',
        ]);

        Transaction::factory()->for($user)->for($savingsAccount)->create([
            'description' => 'TRANSFER TO EVERYDAY',
            'amount' => 100000,
            'post_date' => '2026-03-03',
        ]);

        Transaction::factory()->credit()->for($user)->for($savingsAccount)->create([
            'description' => 'INTEREST PAYMENT',
            'amount' => 4523,
            'post_date' => '2026-03-01',
        ]);

        Transaction::factory()->for($user)->for($transactionAccount)->fromBasiq()->create([
            'description' => 'CHEMIST WAREHOUSE SYDNEY',
            'clean_description' => 'Chemist Warehouse',
            'amount' => 3250,
            'post_date' => '2026-03-04',
        ]);

        Transaction::factory()->for($user)->for($transactionAccount)->create([
            'description' => 'ALDI STORES NEWTOWN',
            'amount' => 6780,
            'post_date' => '2026-03-12',
            'category_id' => $groceries?->id,
        ]);

        Transaction::factory()->for($user)->for($transactionAccount)->fromBasiq()->create([
            'description' => 'KMART AUSTRALIA CHATSWOOD',
            'clean_description' => 'Kmart Chatswood',
            'amount' => 4500,
            'post_date' => '2026-03-07',
        ]);

        Transaction::factory()->credit()->for($user)->for($transactionAccount)->create([
            'description' => 'REFUND KMART AUSTRALIA',
            'amount' => 2500,
            'post_date' => '2026-03-09',
        ]);

        Transaction::factory()->for($user)->for($transactionAccount)->create([
            'description' => 'TELSTRA MOBILE BILL',
            'amount' => 8900,
            'post_date' => '2026-03-05',
            'category_id' => $utilities?->id,
        ]);
    }
}
