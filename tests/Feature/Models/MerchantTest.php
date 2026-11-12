<?php

declare(strict_types=1);

use App\Models\Merchant;
use App\Models\MerchantAlias;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

it('stores a random palette colour for an invalid merchant colour', function () {
    $user = User::factory()->create();

    $invalid = Merchant::query()->create(['user_id' => $user->id, 'name' => 'Woolworths', 'colour' => 'nope']);
    $blank = Merchant::query()->create(['user_id' => $user->id, 'name' => 'Coles']);
    $valid = Merchant::query()->create(['user_id' => $user->id, 'name' => 'Aldi', 'colour' => '#123ABC']);

    expect(Merchant::PALETTE)->toContain($invalid->colour)
        ->and(Merchant::PALETTE)->toContain($blank->colour)
        ->and($valid->colour)->toBe('#123ABC');
});

it('keeps merchant names unique per user', function () {
    $user = User::factory()->create();
    Merchant::factory()->for($user)->create(['name' => 'Woolworths', 'merchant_key' => 'WOOLWORTHS']);

    expect(fn () => Merchant::query()->create(['user_id' => $user->id, 'name' => 'Woolworths']))
        ->toThrow(ValidationException::class);

    $other = Merchant::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Woolworths']);

    expect($other->exists)->toBeTrue();
});

it('resolves an aliased merchant key after an edit', function () {
    $user = User::factory()->create();
    $woolworths = Merchant::factory()->for($user)->create(['name' => 'Woolworths', 'merchant_key' => 'WOOLWORTHS']);
    MerchantAlias::factory()->create(['user_id' => $user->id, 'merchant_id' => $woolworths->id, 'merchant_key' => 'WOOLIES']);

    $transaction = Transaction::factory()->for($user)->create(['merchant_name' => 'Woolies']);
    $edited = $transaction->createChild(['merchant_name' => 'Woolies', 'description' => 'Woolies Bondi']);

    expect($transaction->merchant_id)->toBe($woolworths->id)
        ->and($edited->merchant_key)->toBe('WOOLIES')
        ->and($edited->merchant_id)->toBe($woolworths->id)
        ->and(MerchantAlias::query()->where('user_id', $user->id)->value('merchant_key'))->toBe('WOOLIES');
});

it('ignores another users alias when resolving a merchant key', function () {
    $owner = User::factory()->create();
    $woolworths = Merchant::factory()->for($owner)->create(['name' => 'Woolworths', 'merchant_key' => 'WOOLWORTHS']);
    MerchantAlias::factory()->create(['user_id' => $owner->id, 'merchant_id' => $woolworths->id, 'merchant_key' => 'WOOLIES']);

    $transaction = Transaction::factory()->for(User::factory()->create())->create(['merchant_name' => 'Woolies']);

    expect($transaction->merchant_key)->toBe('WOOLIES')
        ->and($transaction->merchant_id)->toBeNull();
});

it('nulls the merchant link when a merchant is deleted', function () {
    $user = User::factory()->create();
    $merchant = Merchant::factory()->for($user)->create();
    $transactions = Transaction::factory()->count(5)->for($user)->create(['merchant_id' => $merchant->id]);

    $merchant->delete();

    expect(Transaction::query()->whereIn('id', $transactions->pluck('id'))->whereNull('merchant_id')->count())->toBe(5);
});

it('keeps merchant index names within the MariaDB limit', function () {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')->andReturn('11.4.5-MariaDB');
    $connection = new MariaDbConnection($pdo, 'identifier_probe', '', ['name' => 'identifier_probe', 'driver' => 'mariadb']);
    $originalSchema = Schema::getFacadeRoot();
    Schema::swap($connection->getSchemaBuilder());

    try {
        $queries = $connection->pretend(function () {
            (require database_path('migrations/2026_11_11_300000_create_merchants_table.php'))->up();
            (require database_path('migrations/2026_11_11_300100_create_merchant_aliases_table.php'))->up();
            (require database_path('migrations/2026_11_11_300200_add_merchant_id_to_transactions_table.php'))->up();
        });
    } finally {
        Schema::swap($originalSchema);
    }

    $identifiers = collect($queries)
        ->flatMap(fn (array $query) => preg_match_all('/`([^`]+)`/', $query['query'], $matches) ? $matches[1] : [])
        ->unique();

    expect($identifiers->filter(fn (string $name) => str_contains($name, 'merchant')))->not->toBeEmpty()
        ->and($identifiers->filter(fn (string $name) => mb_strlen($name) > 64)->values()->all())->toBeEmpty();
});

it('clears an alias-derived merchant link when the name stops matching the alias', function () {
    $user = User::factory()->create();
    $woolworths = Merchant::factory()->for($user)->create(['name' => 'Woolworths', 'merchant_key' => 'WOOLWORTHS']);
    MerchantAlias::factory()->create(['user_id' => $user->id, 'merchant_id' => $woolworths->id, 'merchant_key' => 'WOOLIES']);
    $transaction = Transaction::factory()->for($user)->create(['merchant_name' => 'Woolies']);

    $revision = $transaction->createChild(['merchant_name' => 'Corner Cafe']);
    $transaction->update(['merchant_name' => 'Corner Cafe']);

    expect($revision->merchant_key)->toBe('CORNER CAFE')
        ->and($revision->merchant_id)->toBeNull()
        ->and($transaction->fresh()->merchant_id)->toBeNull();
});

it('keeps an explicit merchant link when the name changes', function () {
    $user = User::factory()->create();
    $cafe = Merchant::factory()->for($user)->create(['name' => 'Cafe', 'merchant_key' => 'CAFE']);
    $woolworths = Merchant::factory()->for($user)->create(['name' => 'Woolworths', 'merchant_key' => 'WOOLWORTHS']);
    MerchantAlias::factory()->create(['user_id' => $user->id, 'merchant_id' => $woolworths->id, 'merchant_key' => 'WOOLIES']);
    $explicit = Transaction::factory()->for($user)->create(['merchant_name' => 'Some Shop', 'merchant_id' => $cafe->id]);
    $locked = Transaction::factory()->for($user)->create(['merchant_name' => 'Some Shop', 'merchant_id' => $cafe->id, 'locked_fields' => ['merchant']]);

    $explicit->update(['merchant_name' => 'Woolies']);
    $locked->update(['merchant_name' => 'Woolies']);

    expect($explicit->fresh()->merchant_id)->toBe($cafe->id)
        ->and($locked->fresh()->merchant_id)->toBe($cafe->id);
});

it('rejects a merchant whose normalised key matches another of the same user', function () {
    $user = User::factory()->create();
    Merchant::query()->create(['user_id' => $user->id, 'name' => 'Shop 1']);

    expect(fn () => Merchant::query()->create(['user_id' => $user->id, 'name' => 'Shop 2']))
        ->toThrow(ValidationException::class, 'This name matches the existing merchant "Shop 1".');

    $other = Merchant::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Shop 2']);
    $same = Merchant::query()->where('user_id', $user->id)->sole();
    $same->update(['website' => 'shop.example']);

    expect($other->exists)->toBeTrue()
        ->and($same->fresh()->website)->toBe('shop.example');
});
