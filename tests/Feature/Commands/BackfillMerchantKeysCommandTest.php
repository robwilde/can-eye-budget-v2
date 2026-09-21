<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

it('populates a key on rows written before the column existed', function () {
    $transaction = Transaction::factory()->create([
        'merchant_name' => null,
        'clean_description' => null,
        'description' => 'PAYPAL *STEAM 4829',
    ]);

    // Simulate a pre-migration row: the model hook cannot be bypassed through
    // Eloquent, so clear the column directly.
    DB::table('transactions')->where('id', $transaction->id)->update(['merchant_key' => null]);

    $this->artisan('app:backfill-merchant-keys')->assertSuccessful();

    expect($transaction->fresh()->merchant_key)->toBe('PAYPAL STEAM');
});

it('is safe to run twice and reports no further changes', function () {
    $transaction = Transaction::factory()->create([
        'merchant_name' => null,
        'clean_description' => null,
        'description' => 'NETFLIX.COM',
    ]);

    DB::table('transactions')->where('id', $transaction->id)->update(['merchant_key' => null]);

    $this->artisan('app:backfill-merchant-keys')->assertSuccessful();
    $first = $transaction->fresh()->merchant_key;

    $this->artisan('app:backfill-merchant-keys --recompute')
        ->expectsOutputToContain('Updated 0 of')
        ->assertSuccessful();

    expect($transaction->fresh()->merchant_key)->toBe($first);
});

it('rewrites a stale key under --recompute but leaves it alone by default', function () {
    $transaction = Transaction::factory()->create([
        'merchant_name' => null,
        'clean_description' => null,
        'description' => 'NETFLIX.COM',
    ]);

    DB::table('transactions')->where('id', $transaction->id)->update(['merchant_key' => 'STALE OLD KEY']);

    $this->artisan('app:backfill-merchant-keys')->assertSuccessful();
    expect($transaction->fresh()->merchant_key)->toBe('STALE OLD KEY');

    $this->artisan('app:backfill-merchant-keys --recompute')->assertSuccessful();
    expect($transaction->fresh()->merchant_key)->toBe('NETFLIX.COM');
});

it('writes nothing under --dry-run', function () {
    $transaction = Transaction::factory()->create([
        'merchant_name' => null,
        'clean_description' => null,
        'description' => 'NETFLIX.COM',
    ]);

    DB::table('transactions')->where('id', $transaction->id)->update(['merchant_key' => null]);

    $this->artisan('app:backfill-merchant-keys --dry-run')
        ->expectsOutputToContain('Would update 1 of')
        ->assertSuccessful();

    expect($transaction->fresh()->merchant_key)->toBeNull();
});
