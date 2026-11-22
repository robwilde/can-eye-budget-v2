<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CostBasisSource;
use App\Enums\TradeActivity;
use App\Models\Account;
use App\Models\Holding;
use App\Models\Security;
use App\Models\SecurityPrice;
use App\Models\SecuritySplit;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('is off unless the investments flag is set', function () {
    expect(config('budget.investments.enabled'))->toBeFalse();
});

it('deletes prices and splits with a security but keeps trades', function () {
    $security = Security::factory()->create();
    $account = Account::factory()->create();
    SecurityPrice::factory()->for($security)->create();
    SecuritySplit::factory()->for($security)->create();
    $trade = Trade::factory()->for($account)->for($security)->create();

    $security->delete();

    expect(SecurityPrice::query()->count())->toBe(0)
        ->and(SecuritySplit::query()->count())->toBe(0)
        ->and($trade->fresh()->security_id)->toBeNull();
});

it('refuses to delete a security that an account still holds', function () {
    $holding = Holding::factory()->create();

    expect(fn () => $holding->security->delete())->toThrow(QueryException::class);
});

it('keeps tickers unique per exchange regardless of case', function () {
    Security::factory()->create(['ticker' => 'vas', 'exchange_mic' => 'xasx']);

    expect(Security::query()->first()->ticker)->toBe('VAS')
        ->and(Security::query()->first()->exchange_mic)->toBe('XASX')
        ->and(fn () => Security::factory()->create(['ticker' => 'VAS', 'exchange_mic' => 'XASX']))->toThrow(QueryException::class);

    Security::factory()->create(['ticker' => 'VAS', 'exchange_mic' => 'XNYS']);

    expect(Security::query()->where('ticker', 'VAS')->count())->toBe(2);
});

it('keeps tickers unique when no exchange is given', function () {
    Security::factory()->create(['ticker' => 'abc', 'exchange_mic' => null]);

    expect(fn () => Security::factory()->create(['ticker' => 'ABC', 'exchange_mic' => '']))->toThrow(QueryException::class);
});

it('stores fractional quantities exactly', function () {
    $holding = Holding::factory()->create(['quantity' => '0.12345678']);
    $trade = Trade::factory()->create(['quantity' => '-0.12345678']);
    $split = SecuritySplit::factory()->create(['ratio' => '0.10000000']);

    expect($holding->fresh()->quantity)->toBe('0.12345678')
        ->and($trade->fresh()->quantity)->toBe('-0.12345678')
        ->and($split->fresh()->ratio)->toBe('0.10000000');
});

it('reaches holdings only through the user\'s accounts', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    $myAccount = Account::factory()->for($mine)->create();
    $theirAccount = Account::factory()->for($theirs)->create();
    $myHolding = Holding::factory()->for($myAccount)->create();
    Holding::factory()->for($theirAccount)->create();

    expect(Holding::query()->forUser($mine)->pluck('id')->all())->toBe([$myHolding->id])
        ->and($myAccount->holdings()->pluck('id')->all())->toBe([$myHolding->id])
        ->and(Holding::query()->forUser($theirs->id)->count())->toBe(1);
});

it('reaches trades only through the user\'s accounts', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    $myAccount = Account::factory()->for($mine)->create();
    $theirAccount = Account::factory()->for($theirs)->create();
    $myTrade = Trade::factory()->for($myAccount)->create();
    Trade::factory()->for($theirAccount)->create();

    expect(Trade::query()->forUser($mine)->pluck('id')->all())->toBe([$myTrade->id])
        ->and($myAccount->trades()->pluck('id')->all())->toBe([$myTrade->id]);
});

it('normalises tickers the same way for model writes and for code that skips the model', function () {
    expect(Security::normalise('  vas '))->toBe('VAS');

    $security = Security::factory()->make(['ticker' => null]);

    expect($security->getAttributes()['ticker'])->toBeNull();
});

it('casts money to cents, activities and cost basis sources to enums', function () {
    $holding = Holding::factory()->create(['cost_basis_per_share' => 10050, 'cost_basis_source' => 'calculated']);
    $trade = Trade::factory()->create(['activity' => 'sweep_in', 'fee' => 995]);

    expect($holding->fresh()->cost_basis_per_share)->toBe(10050)
        ->and($holding->fresh()->cost_basis_source)->toBe(CostBasisSource::Calculated)
        ->and($holding->fresh()->cost_basis_locked)->toBeFalse()
        ->and($trade->fresh()->activity)->toBe(TradeActivity::SweepIn)
        ->and($trade->fresh()->fee)->toBe(995);
});

it('keeps one holding per account, security, date and currency', function () {
    $holding = Holding::factory()->create();

    expect(fn () => Holding::factory()->create([
        'account_id' => $holding->account_id,
        'security_id' => $holding->security_id,
        'date' => $holding->date->toDateString(),
        'currency' => $holding->currency,
    ]))->toThrow(QueryException::class);
});

it('keeps investment index names within the MariaDB identifier limit', function () {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')->andReturn('11.4.5-MariaDB');

    $connection = new MariaDbConnection($pdo, 'identifier_probe', '', ['name' => 'identifier_probe', 'driver' => 'mariadb']);
    $originalSchema = Schema::getFacadeRoot();
    Schema::swap($connection->getSchemaBuilder());

    try {
        $queries = $connection->pretend(function () {
            (require database_path('migrations/2026_11_24_000000_create_investment_tables.php'))->up();
        });
    } finally {
        Schema::swap($originalSchema);
    }

    $identifiers = collect($queries)
        ->flatMap(fn (array $query) => preg_match_all('/`([^`]+)`/', $query['query'], $matches) ? $matches[1] : [])
        ->unique();

    expect($identifiers)->toContain('holdings_account_security_date_currency_unique')
        ->and($identifiers->filter(fn (string $identifier): bool => mb_strlen($identifier) > 64)->all())->toBeEmpty();
});
