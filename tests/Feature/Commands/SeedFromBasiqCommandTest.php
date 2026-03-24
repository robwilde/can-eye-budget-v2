<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Jobs\SyncTransactionsJob;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Cache::forget('basiq:server_token');
});

function fakeBasiqApi(): void
{
    Http::fake([
        '*/token' => Http::response(['access_token' => 'tok']),
        '*/users/*/accounts' => Http::response([
            'data' => [
                [
                    'id' => 'acc-cheque',
                    'name' => 'Cheque Account',
                    'institution' => 'AU00000',
                    'class' => ['type' => 'transaction'],
                    'balance' => '9193.32',
                    'currency' => 'AUD',
                    'status' => 'active',
                ],
                [
                    'id' => 'acc-savings',
                    'name' => 'Personal Savings',
                    'institution' => 'AU00000',
                    'class' => ['type' => 'savings'],
                    'balance' => '24000.00',
                    'currency' => 'AUD',
                    'status' => 'active',
                ],
            ],
        ]),
        '*/users/*/transactions*' => Http::response([
            'data' => [
                [
                    'id' => 'txn-1',
                    'amount' => '-42.50',
                    'direction' => 'debit',
                    'description' => 'WOOLWORTHS 1234',
                    'postDate' => '2026-03-10',
                    'transactionDate' => '2026-03-09',
                    'account' => 'acc-cheque',
                    'status' => 'posted',
                ],
            ],
            'links' => ['next' => null],
        ]),
    ]);
}

test('fails when test user does not exist', function () {
    $this->artisan('app:seed-from-basiq')
        ->assertFailed();
});

test('sets basiq_user_id on test user', function () {
    Queue::fake();
    $user = User::factory()->create(['email' => 'test@example.com']);

    $this->artisan('app:seed-from-basiq')
        ->assertSuccessful();

    $user->refresh();
    expect($user->basiq_user_id)->toBe('3470f92c-54d1-4a68-a767-1d031d340d06');
});

test('clears last_synced_at for full sync', function () {
    Queue::fake();
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'last_synced_at' => now(),
    ]);

    $this->artisan('app:seed-from-basiq')
        ->assertSuccessful();

    $user->refresh();
    expect($user->last_synced_at)->toBeNull();
});

test('dispatches SyncTransactionsJob to queue by default', function () {
    Queue::fake();
    User::factory()->create(['email' => 'test@example.com']);

    $this->artisan('app:seed-from-basiq')
        ->assertSuccessful();

    Queue::assertPushed(SyncTransactionsJob::class);
});

test('runs synchronously with --sync flag', function () {
    fakeBasiqApi();
    User::factory()->create(['email' => 'test@example.com']);

    $this->artisan('app:seed-from-basiq', ['--sync' => true])
        ->assertSuccessful();

    expect(Account::count())->toBe(2)
        ->and(Transaction::count())->toBe(1);
});

test('is idempotent on re-run', function () {
    fakeBasiqApi();
    $user = User::factory()->create(['email' => 'test@example.com']);

    $this->artisan('app:seed-from-basiq', ['--sync' => true])
        ->assertSuccessful();

    $user->update(['last_synced_at' => null]);

    $this->artisan('app:seed-from-basiq', ['--sync' => true])
        ->assertSuccessful();

    expect(Account::count())->toBe(2)
        ->and(Transaction::count())->toBe(1);
});
