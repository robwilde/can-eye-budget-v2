<?php

declare(strict_types=1);

use App\Enums\AccountClass;
use App\Enums\ImportSource;
use App\Enums\TransactionDirection;
use App\Enums\ValuationKind;
use App\Exceptions\InvalidValuationException;
use App\Models\Account;
use App\Models\AccountValuation;
use App\Models\Transaction;
use App\Services\AccountValuationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo('2026-10-22');
    $this->service = new AccountValuationService;
});

function cashAccount(array $attributes = []): Account
{
    return Account::factory()->create([
        'type' => AccountClass::Transaction,
        'balance_source' => ImportSource::Manual,
        'balance' => 0,
        ...$attributes,
    ]);
}

function post(Account $account, string $date, int $amount, TransactionDirection $direction = TransactionDirection::Debit): Transaction
{
    return Transaction::factory()->for($account)->for($account->user)->create([
        'post_date' => $date,
        'amount' => $amount,
        'direction' => $direction,
    ]);
}

test('shifts the opening balance when setting the current balance on a cash account', function () {
    $account = cashAccount();
    $this->service->setOpeningBalance($account, 100000, CarbonImmutable::parse('2026-05-01'));
    post($account, '2026-05-03', 20000);

    $this->service->setCurrentBalance($account, 90000);

    $opening = $account->valuations()->sole();
    expect($opening->kind)->toBe(ValuationKind::OpeningBalance)
        ->and($opening->balance)->toBe(110000)
        ->and($account->valuations()->where('kind', ValuationKind::Reconciliation)->count())->toBe(0)
        ->and($account->fresh()->balance)->toBe(90000);
});

test('creates an opening balance when setting the current balance on a cash account with none', function () {
    $account = cashAccount();
    post($account, '2026-09-01', 2000);
    post($account, '2026-09-10', 5000, TransactionDirection::Credit);

    $valuation = $this->service->setCurrentBalance($account, 50000);

    expect($valuation->kind)->toBe(ValuationKind::OpeningBalance)
        ->and($valuation->valued_on->toDateString())->toBe('2024-10-22')
        ->and($valuation->balance)->toBe(47000)
        ->and($account->fresh()->balance)->toBe(50000);
});

test('rejects an opening balance not before the oldest entry', function () {
    $account = cashAccount();
    post($account, '2026-03-01', 1000);

    expect(fn () => $this->service->setOpeningBalance($account, 5000, CarbonImmutable::parse('2026-03-01')))
        ->toThrow(InvalidValuationException::class, 'Opening balance date must be before the oldest entry date')
        ->and($account->valuations()->count())->toBe(0);
});

test('defaults the opening date to the day before an entry older than two years', function () {
    $account = cashAccount();
    post($account, '2023-01-10', 1000);

    $valuation = $this->service->setOpeningBalance($account, 5000);

    expect($valuation->valued_on->toDateString())->toBe('2023-01-09');
});

test('rejects a valuation dated 30 or more years back', function () {
    $account = cashAccount();

    expect(fn () => $this->service->setOpeningBalance($account, 100, CarbonImmutable::parse('1996-10-22')))
        ->toThrow(InvalidValuationException::class)
        ->and(fn () => $this->service->reconcile($account, 100, CarbonImmutable::parse('1990-01-01')))
        ->toThrow(InvalidValuationException::class);
});

test('updates an existing valuation when reconciling the same date', function () {
    $account = Account::factory()->savings()->create(['balance_source' => ImportSource::Manual]);
    $this->service->reconcile($account, 50000, CarbonImmutable::parse('2026-06-01'));

    $this->service->reconcile($account, 60000, CarbonImmutable::parse('2026-06-01'));

    $valuation = $account->valuations()->sole();
    expect($valuation->valued_on->toDateString())->toBe('2026-06-01')
        ->and($valuation->balance)->toBe(60000);
});

test('adds a reconciliation when setting the current balance on a non-cash account', function () {
    $account = Account::factory()->savings()->create(['balance_source' => ImportSource::Manual]);
    $this->service->setOpeningBalance($account, 10000, CarbonImmutable::parse('2026-01-01'));

    $this->service->setCurrentBalance($account, 12000);
    $this->service->setCurrentBalance($account, 13000);

    $reconciliations = $account->valuations()->where('kind', ValuationKind::Reconciliation)->get();
    expect($reconciliations)->toHaveCount(1)
        ->and($reconciliations->first()->valued_on->toDateString())->toBe('2026-10-22')
        ->and($reconciliations->first()->balance)->toBe(13000)
        ->and($account->valuations()->where('kind', ValuationKind::OpeningBalance)->sole()->balance)->toBe(10000);
});

test('records the difference from the balance implied before a reconciliation', function () {
    $account = cashAccount();
    $this->service->setOpeningBalance($account, 100000, CarbonImmutable::parse('2026-05-01'));
    post($account, '2026-05-03', 2000);

    $reconciliation = $this->service->reconcile($account, 90000, CarbonImmutable::parse('2026-05-10'));

    expect($reconciliation->difference)->toBe(-8000)
        ->and($account->fresh()->reconcile_difference)->toBe(-8000)
        ->and($account->fresh()->reconciled_on->toDateString())->toBe('2026-05-10');
});

test('keeps the legacy difference against the stored balance for an account with no valuations', function () {
    $account = Account::factory()->savings()->create(['balance' => 100000, 'balance_source' => ImportSource::Manual]);

    $this->service->reconcile($account, 125000, CarbonImmutable::parse('2026-07-01'));

    expect($account->fresh()->reconcile_difference)->toBe(25000)
        ->and($account->fresh()->balance)->toBe(125000);
});

test('refuses to reconcile on or before the opening balance date', function () {
    $account = cashAccount();
    $this->service->setOpeningBalance($account, 1000, CarbonImmutable::parse('2026-05-01'));

    expect(fn () => $this->service->reconcile($account, 2000, CarbonImmutable::parse('2026-05-01')))
        ->toThrow(InvalidValuationException::class)
        ->and(fn () => $this->service->reconcile($account, 2000, CarbonImmutable::parse('2026-04-01')))
        ->toThrow(InvalidValuationException::class);
});

test('falls back to the previous reconciliation when the latest one is deleted', function () {
    $account = Account::factory()->savings()->create(['balance_source' => ImportSource::Manual]);
    $first = $this->service->reconcile($account, 10000, CarbonImmutable::parse('2026-08-01'));
    $second = $this->service->reconcile($account, 15000, CarbonImmutable::parse('2026-09-01'));

    $this->service->delete($second);

    expect($account->fresh()->reconciled_on->toDateString())->toBe('2026-08-01')
        ->and($account->fresh()->reconcile_difference)->toBe($first->difference)
        ->and($account->fresh()->balance)->toBe(10000);

    $this->service->delete($first);

    expect($account->fresh()->reconciled_on)->toBeNull()
        ->and($account->fresh()->reconcile_difference)->toBeNull();
});

test('moves a reconciliation to a free date and refuses a taken one', function () {
    $account = Account::factory()->savings()->create(['balance_source' => ImportSource::Manual]);
    $first = $this->service->reconcile($account, 10000, CarbonImmutable::parse('2026-08-01'));
    $this->service->reconcile($account, 15000, CarbonImmutable::parse('2026-09-01'));

    $moved = $this->service->update($first, 11000, CarbonImmutable::parse('2026-08-15'));

    expect($moved->valued_on->toDateString())->toBe('2026-08-15')
        ->and($moved->balance)->toBe(11000)
        ->and(fn () => $this->service->update($moved, 11000, CarbonImmutable::parse('2026-09-01')))
        ->toThrow(InvalidValuationException::class)
        ->and($account->valuations()->count())->toBe(2);
});

test('does not overwrite a provider-fed balance', function () {
    $account = Account::factory()->create(['balance' => 777700, 'balance_source' => ImportSource::Redbark]);

    $this->service->reconcile($account, 1000, CarbonImmutable::parse('2026-07-01'));

    expect($account->fresh()->balance)->toBe(777700)
        ->and($account->valuations()->count())->toBe(1);
});

test('counts only the current version of an edited transaction in a derived balance', function () {
    $account = cashAccount();
    $this->service->setOpeningBalance($account, 100000, CarbonImmutable::parse('2026-05-01'));
    $original = post($account, '2026-05-03', 2000);
    $original->createChild(['amount' => 3000]);

    $this->service->setCurrentBalance($account, 90000);

    expect($account->valuations()->sole()->balance)->toBe(93000);
});

test('writes nothing when a valuation write fails midway', function () {
    $account = cashAccount();
    $this->service->setOpeningBalance($account, 1000, CarbonImmutable::parse('2026-05-01'));

    try {
        DB::transaction(function () use ($account): void {
            $this->service->reconcile($account, 2000, CarbonImmutable::parse('2026-06-01'));

            throw new RuntimeException('late failure');
        });
    } catch (RuntimeException) {
    }

    expect($account->valuations()->count())->toBe(1)
        ->and($account->fresh()->reconciled_on)->toBeNull();
});

test('valuation writes on one account leave another account untouched', function () {
    $mine = cashAccount();
    $theirs = Account::factory()->create(['balance' => 4200, 'balance_source' => ImportSource::Manual]);

    $this->service->reconcile($mine, 1000, CarbonImmutable::parse('2026-07-01'));

    expect($theirs->fresh()->balance)->toBe(4200)
        ->and(AccountValuation::query()->where('account_id', $theirs->id)->count())->toBe(0);
});

test('refreshes a later difference when an earlier reconciliation is added', function () {
    $account = Account::factory()->savings()->create(['balance' => 100000, 'balance_source' => ImportSource::Manual]);
    $this->service->setOpeningBalance($account, 100000, CarbonImmutable::parse('2026-01-01'));
    $later = $this->service->reconcile($account, 100000, CarbonImmutable::parse('2026-10-22'));

    expect($later->fresh()->difference)->toBe(0);

    $this->service->reconcile($account, 150000, CarbonImmutable::parse('2026-10-01'));

    expect($later->fresh()->difference)->toBe(-50000)
        ->and($account->fresh()->reconcile_difference)->toBe(-50000)
        ->and($account->fresh()->reconciled_on->toDateString())->toBe('2026-10-22');
});

test('works backwards from the nearest later valuation when reconciling before every valuation', function () {
    $account = cashAccount(['type' => AccountClass::Savings]);
    $this->service->reconcile($account, 100000, CarbonImmutable::parse('2026-10-22'));
    post($account, '2026-10-10', 30000, TransactionDirection::Credit);

    $earlier = $this->service->reconcile($account, 150000, CarbonImmutable::parse('2026-10-01'));

    expect($this->service->balanceOn($account, CarbonImmutable::parse('2026-10-01')))->toBe(150000)
        ->and($earlier->difference)->toBe(150000 - (100000 - 30000));
});

test('records no difference for a back-dated first reconciliation that matches the stored balance', function () {
    $account = cashAccount(['type' => AccountClass::Savings, 'balance' => 95000]);
    post($account, '2026-07-05', 5000);

    $valuation = $this->service->reconcile($account, 100000, CarbonImmutable::parse('2026-07-01'));

    expect($valuation->difference)->toBe(0)
        ->and($this->service->update($valuation->fresh(), 100000)->difference)->toBe(0)
        ->and($account->fresh()->balance)->toBe(95000);
});

test('keeps the legacy last-reconcile summary until a reconciliation is deleted', function () {
    $account = cashAccount(['reconciled_on' => '2026-09-01', 'reconcile_difference' => 1234]);

    $this->service->setOpeningBalance($account, 5000, CarbonImmutable::parse('2026-05-01'));

    expect($account->fresh()->reconciled_on->toDateString())->toBe('2026-09-01')
        ->and($account->fresh()->reconcile_difference)->toBe(1234);

    $reconciliation = $this->service->reconcile($account, 9000, CarbonImmutable::parse('2026-10-01'));
    $this->service->delete($reconciliation);

    expect($account->fresh()->reconciled_on)->toBeNull()
        ->and($account->fresh()->reconcile_difference)->toBeNull();
});
