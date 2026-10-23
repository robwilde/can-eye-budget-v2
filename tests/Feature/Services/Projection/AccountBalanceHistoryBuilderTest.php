<?php

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Enums\TransactionDirection;
use App\Enums\ValuationKind;
use App\Models\Account;
use App\Models\AccountDailyBalance;
use App\Models\AccountValuation;
use App\Models\Transaction;
use App\Services\AccountValuationService;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo('2026-05-12');
    $this->builder = app(AccountBalanceHistoryBuilder::class);
});

function historyAccount(int $balance = 0): Account
{
    return Account::factory()->create(['balance' => $balance, 'balance_source' => ImportSource::Manual]);
}

function postOn(Account $account, string $date, int $amount, TransactionDirection $direction): Transaction
{
    return Transaction::factory()->for($account)->for($account->user)->create([
        'post_date' => $date,
        'amount' => $amount,
        'direction' => $direction,
    ]);
}

function dayRow(Account $account, string $date): AccountDailyBalance
{
    return $account->dailyBalances()->whereDate('balance_date', $date)->sole();
}

function openingOn(Account $account, string $date, int $balance): AccountValuation
{
    return AccountValuation::factory()->for($account)->opening()->create(['valued_on' => $date, 'balance' => $balance]);
}

test('rebuilds daily balances from the opening balance and transactions', function () {
    $account = historyAccount();
    openingOn($account, '2026-05-01', 100000);
    postOn($account, '2026-05-03', 2000, TransactionDirection::Debit);
    postOn($account, '2026-05-05', 5000, TransactionDirection::Credit);

    $this->builder->rebuild($account);

    expect(dayRow($account, '2026-05-01')->end_balance)->toBe(100000)
        ->and(dayRow($account, '2026-05-03')->outflows)->toBe(2000)
        ->and(dayRow($account, '2026-05-03')->end_balance)->toBe(98000)
        ->and(dayRow($account, '2026-05-05')->inflows)->toBe(5000)
        ->and(dayRow($account, '2026-05-05')->outflows)->toBe(0)
        ->and(dayRow($account, '2026-05-05')->end_balance)->toBe(103000)
        ->and($account->dailyBalances()->count())->toBe(12)
        ->and(dayRow($account, '2026-05-12')->end_balance)->toBe(103000);
});

test('resets the end balance on a reconciliation date', function () {
    $account = historyAccount();
    openingOn($account, '2026-05-01', 100000);
    postOn($account, '2026-05-03', 2000, TransactionDirection::Debit);
    postOn($account, '2026-05-05', 5000, TransactionDirection::Credit);
    AccountValuation::factory()->for($account)->create(['kind' => ValuationKind::Reconciliation, 'valued_on' => '2026-05-10', 'balance' => 80000]);
    postOn($account, '2026-05-11', 1000, TransactionDirection::Debit);

    $this->builder->rebuild($account);

    $reconciled = dayRow($account, '2026-05-10');
    expect($reconciled->start_balance)->toBe(103000)
        ->and($reconciled->adjustments)->toBe(-23000)
        ->and($reconciled->end_balance)->toBe(80000)
        ->and(dayRow($account, '2026-05-11')->start_balance)->toBe(80000)
        ->and(dayRow($account, '2026-05-11')->end_balance)->toBe(79000);
});

test('counts only the current version of an edited transaction', function () {
    $account = historyAccount();
    openingOn($account, '2026-05-01', 100000);
    postOn($account, '2026-05-03', 2000, TransactionDirection::Debit)->createChild(['amount' => 3000]);

    $this->builder->rebuild($account);

    expect(dayRow($account, '2026-05-03')->outflows)->toBe(3000)
        ->and(dayRow($account, '2026-05-03')->end_balance)->toBe(97000);
});

test('rebuilds only the given accounts history', function () {
    $mine = historyAccount();
    $theirs = historyAccount(4200);
    openingOn($mine, '2026-05-01', 100000);
    $this->builder->rebuild($theirs);
    $before = $theirs->dailyBalances()->orderBy('id')->get(['id', 'balance_date', 'end_balance'])->toArray();

    $this->builder->rebuild($mine);

    expect($theirs->dailyBalances()->orderBy('id')->get(['id', 'balance_date', 'end_balance'])->toArray())->toBe($before)
        ->and($mine->dailyBalances()->count())->toBe(12);
});

test('rolls back the history rebuild with a failed valuation write', function () {
    $account = historyAccount();
    $service = app(AccountValuationService::class);
    $service->setOpeningBalance($account, 100000, CarbonImmutable::parse('2026-05-01'));
    $before = $account->dailyBalances()->orderBy('balance_date')->pluck('end_balance', 'id')->all();

    try {
        DB::transaction(function () use ($service, $account): void {
            $service->reconcile($account, 50000, CarbonImmutable::parse('2026-05-08'));

            throw new RuntimeException('late failure');
        });
    } catch (RuntimeException) {
    }

    expect($account->valuations()->count())->toBe(1)
        ->and($account->dailyBalances()->orderBy('balance_date')->pluck('end_balance', 'id')->all())->toBe($before);
});

test('rebuilds the history when a valuation is written', function () {
    $account = historyAccount();

    app(AccountValuationService::class)->setOpeningBalance($account, 100000, CarbonImmutable::parse('2026-05-01'));
    app(AccountValuationService::class)->reconcile($account, 90000, CarbonImmutable::parse('2026-05-08'));

    expect(dayRow($account, '2026-05-07')->end_balance)->toBe(100000)
        ->and(dayRow($account, '2026-05-08')->end_balance)->toBe(90000)
        ->and(dayRow($account, '2026-05-12')->end_balance)->toBe(90000);
});

test('works backwards from the stored balance when there is no valuation', function () {
    $account = historyAccount(50000);
    postOn($account, '2026-05-04', 10000, TransactionDirection::Credit);
    postOn($account, '2026-05-06', 3000, TransactionDirection::Debit);

    $this->builder->rebuild($account);

    expect(dayRow($account, '2026-05-04')->start_balance)->toBe(43000)
        ->and(dayRow($account, '2026-05-04')->end_balance)->toBe(53000)
        ->and(dayRow($account, '2026-05-12')->end_balance)->toBe(50000)
        ->and($account->dailyBalances()->sum('adjustments'))->toBe(0);
});

test('uses the current stored balance when the given account is stale', function () {
    $account = historyAccount(50000);
    postOn($account, '2026-05-04', 10000, TransactionDirection::Credit);
    Account::query()->whereKey($account->id)->update(['balance' => 75000]);

    $this->builder->rebuild($account);

    expect(dayRow($account, '2026-05-04')->start_balance)->toBe(65000)
        ->and(dayRow($account, '2026-05-12')->end_balance)->toBe(75000);
});

test('works backwards from the earliest valuation when there is no opening balance', function () {
    $account = historyAccount(999999);
    postOn($account, '2026-05-04', 10000, TransactionDirection::Credit);
    AccountValuation::factory()->for($account)->create(['kind' => ValuationKind::Reconciliation, 'valued_on' => '2026-05-08', 'balance' => 70000]);

    $this->builder->rebuild($account);

    expect(dayRow($account, '2026-05-04')->start_balance)->toBe(60000)
        ->and(dayRow($account, '2026-05-08')->adjustments)->toBe(0)
        ->and(dayRow($account, '2026-05-12')->end_balance)->toBe(70000);
});

test('keeps rows before a partial rebuild when a valuation pins them', function () {
    $account = historyAccount();
    openingOn($account, '2026-05-01', 100000);
    $this->builder->rebuild($account);
    $earlyId = dayRow($account, '2026-05-03')->id;
    postOn($account, '2026-05-09', 4000, TransactionDirection::Debit);

    $this->builder->rebuild($account, CarbonImmutable::parse('2026-05-09'));

    expect(dayRow($account, '2026-05-03')->id)->toBe($earlyId)
        ->and(dayRow($account, '2026-05-09')->end_balance)->toBe(96000)
        ->and($account->dailyBalances()->count())->toBe(12);
});

test('rebuilds everything when a partial rebuild has nothing pinning earlier days', function () {
    $account = historyAccount(50000);
    postOn($account, '2026-05-04', 10000, TransactionDirection::Credit);
    $this->builder->rebuild($account);
    postOn($account, '2026-05-09', 5000, TransactionDirection::Credit);

    $this->builder->rebuild($account, CarbonImmutable::parse('2026-05-09'));

    expect(dayRow($account, '2026-05-04')->start_balance)->toBe(35000)
        ->and(dayRow($account, '2026-05-12')->end_balance)->toBe(50000);
});

test('ignores pending, deleted, future and pre-opening transactions', function () {
    $account = historyAccount();
    openingOn($account, '2026-05-01', 100000);
    postOn($account, '2026-04-28', 7000, TransactionDirection::Credit);
    Transaction::factory()->pending()->for($account)->for($account->user)->create([
        'post_date' => '2026-05-03',
        'amount' => 1000,
        'direction' => TransactionDirection::Debit,
    ]);
    postOn($account, '2026-05-04', 2000, TransactionDirection::Debit)->delete();
    postOn($account, '2026-05-20', 9000, TransactionDirection::Debit);

    $this->builder->rebuild($account);

    expect(dayRow($account, '2026-05-03')->outflows)->toBe(0)
        ->and(dayRow($account, '2026-05-04')->outflows)->toBe(0)
        ->and(dayRow($account, '2026-05-12')->end_balance)->toBe(100000)
        ->and($account->dailyBalances()->count())->toBe(12)
        ->and($account->dailyBalances()->whereDate('balance_date', '<', '2026-05-01')->exists())->toBeFalse();
});
