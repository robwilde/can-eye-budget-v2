<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PayFrequency;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\Budget;
use App\Models\Category;
use App\Models\PipelineRun;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;

test('factory creates a valid user', function () {
    $user = User::factory()->create();

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->exists)->toBeTrue();
});

test('basiq_user_id is nullable', function () {
    $user = User::factory()->create();

    expect($user->basiq_user_id)->toBeNull();
});

test('basiq_user_id must be unique', function () {
    $basiqId = 'unique-basiq-id';
    User::factory()->create(['basiq_user_id' => $basiqId]);

    expect(fn () => User::factory()->create(['basiq_user_id' => $basiqId]))
        ->toThrow(QueryException::class);
});

test('withBasiq state sets basiq_user_id', function () {
    $user = User::factory()->withBasiq()->create();

    expect($user->basiq_user_id)->not->toBeNull()
        ->and($user->basiq_user_id)->toBeString();
});

test('user has many accounts', function () {
    $user = User::factory()->create();
    Account::factory()->count(3)->for($user)->create();

    expect($user->accounts)->toHaveCount(3)
        ->each(fn (Pest\Expectation $account) => $account->toBeInstanceOf(Account::class));
});

test('user has many transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Transaction::factory()->count(3)->for($user)->for($account)->create();

    expect($user->transactions)->toHaveCount(3)
        ->each(fn (Pest\Expectation $transaction) => $transaction->toBeInstanceOf(Transaction::class));
});

test('user has many budgets', function () {
    $user = User::factory()->create();
    Budget::factory()->count(3)->for($user)->create();

    expect($user->budgets)->toHaveCount(3)
        ->each(fn (Pest\Expectation $budget) => $budget->toBeInstanceOf(Budget::class));
});

test('hasPayCycleConfigured returns false when no pay cycle fields set', function () {
    $user = User::factory()->create();

    expect($user->hasPayCycleConfigured())->toBeFalse();
});

test('hasPayCycleConfigured returns false when only some fields set', function () {
    $user = User::factory()->create([
        'pay_amount' => 300000,
        'pay_frequency' => PayFrequency::Fortnightly,
    ]);

    expect($user->hasPayCycleConfigured())->toBeFalse();
});

test('hasPayCycleConfigured returns true when all fields set', function () {
    $user = User::factory()->withPayCycle()->create();

    expect($user->hasPayCycleConfigured())->toBeTrue();
});

test('totalOwed sums abs balance for credit card and loan accounts', function () {
    $user = User::factory()->create();
    Account::factory()->creditCard()->for($user)->create(['balance' => -50000]);
    Account::factory()->loan()->for($user)->create(['balance' => -300000]);
    Account::factory()->for($user)->create(['balance' => 100000]);
    Account::factory()->savings()->for($user)->create(['balance' => 200000]);

    expect($user->totalOwed())->toBe(350000);
});

test('totalOwed returns zero when no debt accounts exist', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);

    expect($user->totalOwed())->toBe(0);
});

test('totalOwed excludes inactive and closed accounts', function () {
    $user = User::factory()->create();
    Account::factory()->creditCard()->for($user)->create(['balance' => -50000]);
    Account::factory()->creditCard()->inactive()->for($user)->create(['balance' => -80000]);
    Account::factory()->loan()->closed()->for($user)->create(['balance' => 0]);

    expect($user->totalOwed())->toBe(50000);
});

test('totalAvailable sums available balance for spendable accounts', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);
    Account::factory()->savings()->for($user)->create(['balance' => 200000]);
    Account::factory()->creditCard()->for($user)->create([
        'balance' => -50000,
        'credit_limit' => 500000,
    ]);

    expect($user->totalAvailable())->toBe(750000);
});

test('totalAvailable excludes loans and mortgages', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);
    Account::factory()->loan()->for($user)->create(['balance' => -500000]);
    Account::factory()->mortgage()->for($user)->create(['balance' => -50000000]);

    expect($user->totalAvailable())->toBe(100000);
});

test('totalAvailable returns zero when no spendable accounts exist', function () {
    $user = User::factory()->create();
    Account::factory()->mortgage()->for($user)->create(['balance' => -30000000]);

    expect($user->totalAvailable())->toBe(0);
});

test('daysUntilNextPay returns correct days when pay cycle configured', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(5),
    ]);

    expect($user->daysUntilNextPay())->toBe(5);
});

test('daysUntilNextPay returns null when no pay cycle', function () {
    $user = User::factory()->create();

    expect($user->daysUntilNextPay())->toBeNull();
});

test('daysUntilNextPay returns zero on pay day', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->startOfDay(),
    ]);

    expect($user->daysUntilNextPay())->toBe(0);
});

test('bufferUntilNextPay returns available minus planned spend until payday', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(14),
    ]);
    $account = Account::factory()->for($user)->create(['balance' => 200000]);

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 50000,
        'is_active' => true,
        'start_date' => now()->addDays(3),
        'frequency' => RecurrenceFrequency::DontRepeat,
    ]);

    expect($user->bufferUntilNextPay($user->totalAvailable()))
        ->toBe($user->totalAvailable() - $user->totalNeededUntilPayday());
});

test('bufferUntilNextPay returns null when no pay cycle configured', function () {
    $user = User::factory()->create();

    expect($user->bufferUntilNextPay(200000))->toBeNull();
});

test('withPayCycle factory state sets all pay cycle fields', function () {
    $user = User::factory()->withPayCycle()->create();

    expect($user->pay_amount)->not->toBeNull()
        ->and($user->pay_frequency)->toBe(PayFrequency::Fortnightly)
        ->and($user->next_pay_date)->not->toBeNull();
});

test('user has primary account relationship', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $user->update(['primary_account_id' => $account->id]);

    expect($user->fresh()->primaryAccount->id)->toBe($account->id);
});

test('user has pipeline runs relationship', function () {
    $user = User::factory()->create();
    PipelineRun::factory()->count(3)->for($user)->create();

    expect($user->pipelineRuns)->toHaveCount(3)
        ->each(fn (Pest\Expectation $run) => $run->toBeInstanceOf(PipelineRun::class));
});

test('user has analysis suggestions relationship', function () {
    $user = User::factory()->create();
    $run = PipelineRun::factory()->for($user)->create();
    AnalysisSuggestion::factory()->count(3)->for($run)->create(['user_id' => $user->id]);

    expect($user->analysisSuggestions)->toHaveCount(3)
        ->each(fn (Pest\Expectation $suggestion) => $suggestion->toBeInstanceOf(AnalysisSuggestion::class));
});

// ── totalNeededUntilPayday() ──────────────────────────────────────

test('totalNeededUntilPayday returns zero when no pay cycle configured', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 10000,
        'start_date' => now(),
        'frequency' => RecurrenceFrequency::EveryWeek,
    ]);

    expect($user->totalNeededUntilPayday())->toBe(0);
});

test('totalNeededUntilPayday sums debit occurrences between today and next pay date', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(14)->startOfDay(),
    ]);
    $account = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 5000,
        'start_date' => now()->addDays(2)->startOfDay(),
        'frequency' => RecurrenceFrequency::EveryWeek,
    ]);

    expect($user->totalNeededUntilPayday())->toBe(10000);
});

test('totalNeededUntilPayday excludes credit direction plans', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(10)->startOfDay(),
    ]);
    $account = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Credit,
        'amount' => 50000,
        'start_date' => now()->addDays(3)->startOfDay(),
        'frequency' => RecurrenceFrequency::DontRepeat,
    ]);

    expect($user->totalNeededUntilPayday())->toBe(0);
});

test('totalNeededUntilPayday excludes inactive plans', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(10)->startOfDay(),
    ]);
    $account = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->inactive()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 7500,
        'start_date' => now()->addDays(2)->startOfDay(),
        'frequency' => RecurrenceFrequency::EveryWeek,
    ]);

    expect($user->totalNeededUntilPayday())->toBe(0);
});

test('totalNeededUntilPayday uses absolute amount', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(10)->startOfDay(),
    ]);
    $account = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Debit,
        'amount' => -4200,
        'start_date' => now()->addDays(2)->startOfDay(),
        'frequency' => RecurrenceFrequency::DontRepeat,
    ]);

    expect($user->totalNeededUntilPayday())->toBe(4200);
});

test('totalNeededUntilPayday excludes planned transfers', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(7),
    ]);
    $account = Account::factory()->for($user)->create();
    $transferParent = Category::factory()->create(['name' => 'Transfer']);
    $transferChild = Category::factory()->create(['parent_id' => $transferParent->id, 'name' => 'Optimus to CC']);
    $billCategory = Category::factory()->create(['name' => 'Insurance']);

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 25000,
        'category_id' => $billCategory->id,
        'is_active' => true,
        'start_date' => now()->addDay(),
        'frequency' => RecurrenceFrequency::DontRepeat,
    ]);
    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 250000,
        'category_id' => $transferChild->id,
        'is_active' => true,
        'start_date' => now()->addDay(),
        'frequency' => RecurrenceFrequency::DontRepeat,
    ]);

    expect($user->totalNeededUntilPayday())->toBe(25000);
});

test('totalNeededUntilPayday excludes planned transfers categorised at the parent Transfer level', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(7),
    ]);
    $account = Account::factory()->for($user)->create();
    $transferParent = Category::factory()->create(['name' => 'Transfer']);
    $billCategory = Category::factory()->create(['name' => 'Insurance']);

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 25000,
        'category_id' => $billCategory->id,
        'is_active' => true,
        'start_date' => now()->addDay(),
        'frequency' => RecurrenceFrequency::DontRepeat,
    ]);
    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 250000,
        'category_id' => $transferParent->id,
        'is_active' => true,
        'start_date' => now()->addDay(),
        'frequency' => RecurrenceFrequency::DontRepeat,
    ]);

    expect($user->totalNeededUntilPayday())->toBe(25000);
});
