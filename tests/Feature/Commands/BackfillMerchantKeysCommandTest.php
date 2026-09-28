<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\MerchantBrandStatus;
use App\Models\MerchantBrand;
use App\Models\Transaction;
use App\Models\User;
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

function brandInState(User $user, string $state, string $merchantKey): MerchantBrand
{
    $factory = MerchantBrand::factory()->for($user);

    return ($state === 'resolved' ? $factory : $factory->{$state}())->create(['merchant_key' => $merchantKey]);
}

function staleKeyedTransaction(User $user, string $description, string $staleKey): Transaction
{
    $transaction = Transaction::factory()->for($user)->create([
        'merchant_name' => null,
        'clean_description' => null,
        'description' => $description,
    ]);

    DB::table('transactions')->where('id', $transaction->id)->update(['merchant_key' => $staleKey]);

    return $transaction;
}

it('rekeys transactions and their brand under --recompute when the signature drops a card token', function () {
    $user = User::factory()->create();
    $transaction = staleKeyedTransaction($user, 'VISA -Afterpay   afterpay.com AU  090263 #8357', 'VISA AFTERPAY AFTERPAY.COM AU');
    $brand = MerchantBrand::factory()->for($user)->create(['merchant_key' => 'VISA AFTERPAY AFTERPAY.COM AU']);
    $updatedAt = $brand->updated_at;

    $this->travel(1)->hour();

    $this->artisan('app:backfill-merchant-keys --recompute')->assertSuccessful();

    $brand->refresh();

    expect($transaction->fresh()->merchant_key)->toBe('AFTERPAY AFTERPAY.COM AU')
        ->and($brand->merchant_key)->toBe('AFTERPAY AFTERPAY.COM AU')
        ->and($brand->updated_at->equalTo($updatedAt))->toBeTrue();
});

it('merges brand rows that collapse onto one key, keeping the strongest status', function (string $winnerKey, string $winnerState, string $loserKey, string $loserState, MerchantBrandStatus $kept) {
    $user = User::factory()->create();
    $winner = brandInState($user, $winnerState, $winnerKey);
    brandInState($user, $loserState, $loserKey);

    $this->artisan('app:backfill-merchant-keys --recompute')->assertSuccessful();

    $rows = MerchantBrand::query()->where('user_id', $user->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->id)->toBe($winner->id)
        ->and($rows->first()->merchant_key)->toBe('PATREON MEMBERSHIP INTERNET IE')
        ->and($rows->first()->status)->toBe($kept);
})->with([
    'resolved old key beats vetoed new key' => ['VISA PATREON MEMBERSHIP INTERNET IE FRGN', 'resolved', 'PATREON MEMBERSHIP INTERNET IE', 'vetoed', MerchantBrandStatus::Resolved],
    'resolved new key beats unresolved old key' => ['PATREON MEMBERSHIP INTERNET IE', 'resolved', 'VISA PATREON MEMBERSHIP INTERNET IE', 'unresolved', MerchantBrandStatus::Resolved],
    'vetoed beats unresolved' => ['VISA PATREON MEMBERSHIP INTERNET IE', 'vetoed', 'PATREON MEMBERSHIP INTERNET IE FRGN', 'unresolved', MerchantBrandStatus::Vetoed],
]);

// Each case is built so the rules below the one under test would pick the other
// row: the older, lower-id row wins every tie the tested rule does not settle.
it('breaks a tie within one status by completeness, then recency, then age', function (array $first, array $second, string $expected) {
    $this->freezeSecond();
    $user = User::factory()->create();
    $rows = [
        'first' => MerchantBrand::factory()->for($user)->create(['merchant_key' => 'VISA NETFLIX.COM', 'partial' => $first[0], 'updated_at' => now()->subMinutes($first[1])]),
        'second' => MerchantBrand::factory()->for($user)->create(['merchant_key' => 'NETFLIX.COM', 'partial' => $second[0], 'updated_at' => now()->subMinutes($second[1])]),
    ];

    $this->artisan('app:backfill-merchant-keys --recompute')->assertSuccessful();

    $survivor = MerchantBrand::query()->where('user_id', $user->id)->sole();

    expect($survivor->id)->toBe($rows[$expected]->id)
        ->and($survivor->merchant_key)->toBe('NETFLIX.COM');
})->with([
    'complete profile beats a newer partial one' => [[true, 0], [false, 60], 'second'],
    'newer beats older' => [[false, 60], [false, 0], 'second'],
    'full tie keeps the older row' => [[false, 0], [false, 0], 'first'],
]);

it('never merges brand rows across users or touches the unknown bucket', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    MerchantBrand::factory()->for($alice)->create(['merchant_key' => 'VISA NETFLIX.COM']);
    MerchantBrand::factory()->for($bob)->vetoed()->create(['merchant_key' => 'NETFLIX.COM FRGN']);
    $unknown = MerchantBrand::factory()->for($alice)->vetoed()->create(['merchant_key' => Transaction::UNKNOWN_MERCHANT_KEY]);

    $this->artisan('app:backfill-merchant-keys --recompute')->assertSuccessful();

    expect(MerchantBrand::query()->where('user_id', $alice->id)->where('merchant_key', 'NETFLIX.COM')->value('status'))->toBe(MerchantBrandStatus::Resolved)
        ->and(MerchantBrand::query()->where('user_id', $bob->id)->where('merchant_key', 'NETFLIX.COM')->value('status'))->toBe(MerchantBrandStatus::Vetoed)
        ->and($unknown->fresh()->merchant_key)->toBe(Transaction::UNKNOWN_MERCHANT_KEY);
});

it('reports brand changes but writes none under --recompute --dry-run', function () {
    $user = User::factory()->create();
    MerchantBrand::factory()->for($user)->create(['merchant_key' => 'VISA NETFLIX.COM']);
    MerchantBrand::factory()->for($user)->vetoed()->create(['merchant_key' => 'NETFLIX.COM']);

    $this->artisan('app:backfill-merchant-keys --recompute --dry-run')
        ->expectsOutputToContain('Would rekey 1 merchant brand(s), merging away 1.')
        ->assertSuccessful();

    expect(MerchantBrand::query()->where('user_id', $user->id)->pluck('merchant_key')->sort()->values()->all())
        ->toBe(['NETFLIX.COM', 'VISA NETFLIX.COM']);
});
