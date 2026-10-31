<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Calendar\MonthFlow;
use App\Support\Calendar\MonthFlowLoader;
use Carbon\CarbonImmutable;

function loadMonthFlow(User $user, string $month = '2026-07-01', string $today = '2026-07-20', int $remainingIncome = 0, int $remainingExpense = 0): MonthFlow
{
    return app(MonthFlowLoader::class)->load(
        $user,
        CarbonImmutable::parse($month),
        CarbonImmutable::parse($today),
        $remainingIncome,
        $remainingExpense,
    );
}

function monthFlowPlan(User $user, Account $account, TransactionDirection $direction, int $amount, string $start, RecurrenceFrequency $frequency = RecurrenceFrequency::EveryMonth, array $overrides = []): PlannedTransaction
{
    return PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => $direction,
        'amount' => $amount,
        'start_date' => $start,
        'frequency' => $frequency,
        ...$overrides,
    ]);
}

function monthFlowPost(User $user, Account $account, TransactionDirection $direction, int $amount, string $date, array $overrides = []): Transaction
{
    $category = Category::query()->firstOrCreate(['user_id' => $user->id, 'name' => $direction === TransactionDirection::Debit ? 'Shopping' : 'Salary']);

    return Transaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'category_id' => $category->id,
        'direction' => $direction,
        'amount' => $direction === TransactionDirection::Debit ? -$amount : $amount,
        'post_date' => $date,
        ...$overrides,
    ]);
}

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

test('a user with no plans or postings has an empty month', function () {
    $flow = loadMonthFlow($this->user);

    expect($flow->isEmpty())->toBeTrue()
        ->and($flow->daysInMonth)->toBe(31)
        ->and($flow->planNet())->toBe(0)
        ->and($flow->actualNet())->toBe(0);
});

test('plan counts every occurrence of the month: a weekly plan is occurrences times amount', function () {
    monthFlowPlan($this->user, $this->account, TransactionDirection::Debit, 4000, '2026-07-01', RecurrenceFrequency::EveryWeek);
    monthFlowPlan($this->user, $this->account, TransactionDirection::Credit, 300000, '2026-07-10');

    $flow = loadMonthFlow($this->user);

    expect($flow->planExpense)->toBe(5 * 4000)
        ->and($flow->planIncome)->toBe(300000);
});

test('plan covers only plans live in the month', function () {
    monthFlowPlan($this->user, $this->account, TransactionDirection::Debit, 1000, '2026-08-01');
    monthFlowPlan($this->user, $this->account, TransactionDirection::Debit, 2000, '2026-05-01', overrides: ['until_date' => '2026-06-30']);
    monthFlowPlan($this->user, $this->account, TransactionDirection::Debit, 3000, '2026-07-31', RecurrenceFrequency::DontRepeat);
    monthFlowPlan($this->user, $this->account, TransactionDirection::Debit, 4000, '2026-07-02', overrides: ['is_active' => false]);

    expect(loadMonthFlow($this->user)->planExpense)->toBe(3000);
});

test('a reconciled occurrence still counts in plan and in actual', function () {
    $plan = monthFlowPlan($this->user, $this->account, TransactionDirection::Debit, 5000, '2026-07-10');
    monthFlowPost($this->user, $this->account, TransactionDirection::Debit, 5000, '2026-07-10', ['planned_transaction_id' => $plan->id]);

    $flow = loadMonthFlow($this->user);

    expect($flow->planExpense)->toBe(5000)
        ->and($flow->actualExpense)->toBe(5000);
});

test('a missed plan stays in the plan and is absent from actual', function () {
    monthFlowPlan($this->user, $this->account, TransactionDirection::Debit, 5000, '2026-07-02');

    $flow = loadMonthFlow($this->user);

    expect($flow->planExpense)->toBe(5000)
        ->and($flow->actualExpense)->toBe(0);
});

test('transfer plans are excluded from plan, whichever side of the tracked boundary they sit on', function () {
    $other = Account::factory()->for($this->user)->create();
    $hidden = Account::factory()->for($this->user)->untracked()->create();

    monthFlowPlan($this->user, $this->account, TransactionDirection::Debit, 9000, '2026-07-05', overrides: ['transfer_to_account_id' => $other->id]);
    monthFlowPlan($this->user, $this->account, TransactionDirection::Debit, 7000, '2026-07-06', overrides: ['transfer_to_account_id' => $hidden->id]);
    monthFlowPlan($this->user, $hidden, TransactionDirection::Debit, 6000, '2026-07-07', overrides: ['transfer_to_account_id' => $this->account->id]);
    monthFlowPlan($this->user, $this->account, TransactionDirection::Debit, 1000, '2026-07-08');

    $flow = loadMonthFlow($this->user);

    expect($flow->planExpense)->toBe(1000)
        ->and($flow->planIncome)->toBe(0);
});

test("another user's plans and postings are excluded", function () {
    $stranger = User::factory()->create();
    $strangerAccount = Account::factory()->for($stranger)->create();

    monthFlowPlan($stranger, $strangerAccount, TransactionDirection::Credit, 900000, '2026-07-10');
    monthFlowPlan($stranger, $strangerAccount, TransactionDirection::Debit, 800000, '2026-07-10');
    monthFlowPost($stranger, $strangerAccount, TransactionDirection::Credit, 700000, '2026-07-10');
    monthFlowPost($stranger, $strangerAccount, TransactionDirection::Debit, 600000, '2026-07-10');
    monthFlowPlan($this->user, $this->account, TransactionDirection::Debit, 1100, '2026-07-10');

    $mine = loadMonthFlow($this->user);

    expect($mine->planIncome)->toBe(0)
        ->and($mine->planExpense)->toBe(1100)
        ->and($mine->actualIncome)->toBe(0)
        ->and($mine->actualExpense)->toBe(0)
        ->and(loadMonthFlow($stranger)->actualIncome)->toBe(700000);
});

test('actual excludes transfers but counts ordinary income and spend, only in the viewed month', function () {
    $other = Account::factory()->for($this->user)->create();
    $hidden = Account::factory()->for($this->user)->untracked()->create();

    $pair = function (Account $from, Account $to, int $amount) {
        $debit = monthFlowPost($this->user, $from, TransactionDirection::Debit, $amount, '2026-07-08');
        $credit = monthFlowPost($this->user, $to, TransactionDirection::Credit, $amount, '2026-07-08', ['transfer_pair_id' => $debit->id]);
        $debit->update(['transfer_pair_id' => $credit->id]);
    };

    $pair($this->account, $hidden, 25000);
    $pair($hidden, $this->account, 40000);
    $pair($this->account, $other, 9999);
    monthFlowPost($this->user, $this->account, TransactionDirection::Credit, 100000, '2026-07-03');
    monthFlowPost($this->user, $this->account, TransactionDirection::Debit, 2500, '2026-07-04');
    monthFlowPost($this->user, $this->account, TransactionDirection::Debit, 777, '2026-06-30');
    monthFlowPost($this->user, $this->account, TransactionDirection::Debit, 888, '2026-08-01');

    $flow = loadMonthFlow($this->user);

    expect($flow->actualIncome)->toBe(100000)
        ->and($flow->actualExpense)->toBe(2500)
        ->and($flow->actualNet())->toBe(97500)
        ->and($flow->actualSavedPercent())->toBe(98);
});

test('saved percentages are income-relative and null without income rather than dividing by zero', function () {
    monthFlowPlan($this->user, $this->account, TransactionDirection::Credit, 200000, '2026-07-01');
    monthFlowPlan($this->user, $this->account, TransactionDirection::Debit, 150000, '2026-07-02');
    monthFlowPost($this->user, $this->account, TransactionDirection::Credit, 100000, '2026-07-03');
    monthFlowPost($this->user, $this->account, TransactionDirection::Debit, 120000, '2026-07-04');

    $flow = loadMonthFlow($this->user);

    expect($flow->planSavedPercent())->toBe(25)
        ->and($flow->actualSavedPercent())->toBe(-20);
});

test('a month with spend but no income has null saved percentages', function () {
    monthFlowPlan($this->user, $this->account, TransactionDirection::Debit, 150000, '2026-07-02');
    monthFlowPost($this->user, $this->account, TransactionDirection::Debit, 5000, '2026-07-04');

    $flow = loadMonthFlow($this->user);

    expect($flow->planIncome)->toBe(0)
        ->and($flow->actualIncome)->toBe(0)
        ->and($flow->planSavedPercent())->toBeNull()
        ->and($flow->actualSavedPercent())->toBeNull()
        ->and($flow->actualNet())->toBe(-5000)
        ->and($flow->isEmpty())->toBeFalse();
});

test('the current month elapses to today and prorates the plan to date', function () {
    monthFlowPlan($this->user, $this->account, TransactionDirection::Credit, 100000, '2026-10-05');
    monthFlowPlan($this->user, $this->account, TransactionDirection::Debit, 31000, '2026-10-05');

    $flow = loadMonthFlow($this->user, month: '2026-10-01', today: '2026-10-20');

    expect($flow->daysInMonth)->toBe(31)
        ->and($flow->elapsedDays)->toBe(20)
        ->and($flow->elapsedPercent())->toBe(65)
        ->and($flow->plannedIncomeToDate())->toBe((int) round(100000 * 20 / 31))
        ->and($flow->plannedExpenseToDate())->toBe(20000)
        ->and($flow->todayLabel)->toBe('20 Oct');
});

test('a past month is fully elapsed and carries no today label', function () {
    monthFlowPlan($this->user, $this->account, TransactionDirection::Credit, 100000, '2026-09-05');

    $flow = loadMonthFlow($this->user, month: '2026-09-01', today: '2026-10-20');

    expect($flow->daysInMonth)->toBe(30)
        ->and($flow->elapsedDays)->toBe(30)
        ->and($flow->elapsedPercent())->toBe(100)
        ->and($flow->plannedIncomeToDate())->toBe(100000)
        ->and($flow->todayLabel)->toBeNull();
});

test('a future month has nothing elapsed and carries no today label', function () {
    monthFlowPlan($this->user, $this->account, TransactionDirection::Credit, 100000, '2026-12-05');

    $flow = loadMonthFlow($this->user, month: '2026-12-01', today: '2026-10-20');

    expect($flow->elapsedDays)->toBe(0)
        ->and($flow->elapsedPercent())->toBe(0)
        ->and($flow->plannedIncomeToDate())->toBe(0)
        ->and($flow->planIncome)->toBe(100000)
        ->and($flow->todayLabel)->toBeNull();
});

test('the last day of a month is fully elapsed on that day', function () {
    $flow = loadMonthFlow($this->user, month: '2026-10-01', today: '2026-10-31');

    expect($flow->elapsedDays)->toBe(31)
        ->and($flow->elapsedPercent())->toBe(100);
});

test('projected net adds the remaining income and spend passed in to the actual net', function () {
    monthFlowPost($this->user, $this->account, TransactionDirection::Credit, 100000, '2026-07-03');
    monthFlowPost($this->user, $this->account, TransactionDirection::Debit, 30000, '2026-07-04');

    $flow = loadMonthFlow($this->user, remainingIncome: 50000, remainingExpense: 20000);

    expect($flow->remainingIncome)->toBe(50000)
        ->and($flow->remainingExpense)->toBe(20000)
        ->and($flow->actualNet())->toBe(70000)
        ->and($flow->projectedNet())->toBe(100000);
});

test('a month with only remaining planned flows is not empty', function () {
    expect(loadMonthFlow($this->user, remainingExpense: 1)->isEmpty())->toBeFalse();
});
