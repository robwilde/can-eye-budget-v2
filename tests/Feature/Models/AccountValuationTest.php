<?php

declare(strict_types=1);

use App\Enums\ValuationKind;
use App\Models\Account;
use App\Models\AccountValuation;
use App\Models\User;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

test('allows only one valuation per account and date', function () {
    $account = Account::factory()->create();
    AccountValuation::factory()->for($account)->create(['valued_on' => '2026-06-01']);

    expect(fn () => AccountValuation::factory()->for($account)->create(['valued_on' => '2026-06-01']))
        ->toThrow(QueryException::class);

    AccountValuation::factory()->for($account)->create(['valued_on' => '2026-06-02']);
    expect($account->valuations()->count())->toBe(2);
});

test('allows only one opening balance per account', function () {
    $account = Account::factory()->create();
    AccountValuation::factory()->for($account)->opening()->create(['valued_on' => '2026-01-01']);

    expect(fn () => AccountValuation::factory()->for($account)->opening()->create(['valued_on' => '2026-02-01']))
        ->toThrow(QueryException::class);

    AccountValuation::factory()->for($account)->create(['valued_on' => '2026-02-01']);
    AccountValuation::factory()->for(Account::factory()->create())->opening()->create(['valued_on' => '2026-01-01']);

    expect($account->valuations()->count())->toBe(2)
        ->and(AccountValuation::query()->where('kind', ValuationKind::OpeningBalance)->count())->toBe(2);
});

test('stores valuation balances as integer cents', function () {
    $valuation = AccountValuation::factory()->create(['balance' => 12345]);

    expect(AccountValuation::query()->find($valuation->id)->balance)->toBe(12345)
        ->and(AccountValuation::factory()->create(['balance' => -250000])->fresh()->balance)->toBe(-250000);
});

test('deletes valuations with their account', function () {
    $account = Account::factory()->create();
    AccountValuation::factory()->count(2)->for($account)->sequence(
        ['valued_on' => '2026-06-01'],
        ['valued_on' => '2026-07-01'],
    )->create();

    $account->delete();

    expect(AccountValuation::query()->count())->toBe(0);
});

test('scopes valuations to the owning user through the account', function () {
    $mine = User::factory()->create();
    $own = AccountValuation::factory()->for(Account::factory()->for($mine)->create())->create();
    AccountValuation::factory()->for(Account::factory()->for(User::factory()->create())->create())->create();

    expect(AccountValuation::query()->forUser($mine)->pluck('id')->all())->toBe([$own->id])
        ->and(AccountValuation::query()->forUser($mine->id)->count())->toBe(1);
});

test('keeps account valuation index names within the MariaDB limit', function () {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')->andReturn('11.4.5-MariaDB');
    $connection = new MariaDbConnection($pdo, 'identifier_probe', '', ['name' => 'identifier_probe', 'driver' => 'mariadb']);
    $originalSchema = Schema::getFacadeRoot();
    Schema::swap($connection->getSchemaBuilder());

    try {
        $queries = $connection->pretend(function () {
            (require database_path('migrations/2026_10_22_300000_create_account_valuations_table.php'))->up();
        });
    } finally {
        Schema::swap($originalSchema);
    }

    $identifiers = collect($queries)
        ->flatMap(fn (array $query) => preg_match_all('/`([^`]+)`/', $query['query'], $matches) ? $matches[1] : [])
        ->unique();

    expect($identifiers->filter(fn (string $name) => str_contains($name, 'account_valuations_')))->not->toBeEmpty()
        ->and($identifiers->filter(fn (string $name) => mb_strlen($name) > 64)->values()->all())->toBeEmpty();
});
