<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\PlannedTransactionStatus;
use App\Enums\PlannedTransactionType;
use App\Enums\PlanOrigin;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\PlannedOccurrence;
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\Projection\PaycheckPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake();
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create(['balance' => 50000]);
    $this->planner = app(PaycheckPlanner::class);
});

function payIncome(object $test, string $name, int $cents, string $start, array $attributes = [], ?User $user = null): PlannedTransaction
{
    $user ??= $test->user;
    $account = $user->is($test->user) ? $test->account : Account::factory()->for($user)->create();

    return PlannedTransaction::factory()->for($user)->for($account)->create([
        'description' => $name, 'amount' => $cents, 'direction' => TransactionDirection::Credit, 'type' => PlannedTransactionType::Income,
        'frequency' => RecurrenceFrequency::Every2Weeks, 'start_date' => $start, 'until_date' => null,
        'origin' => PlanOrigin::Declared, 'status' => PlannedTransactionStatus::Active, 'merchant_key' => null, ...$attributes,
    ]);
}

function payBill(object $test, string $name, int $cents, string $date, array $attributes = [], ?User $user = null): PlannedTransaction
{
    $user ??= $test->user;
    $account = $user->is($test->user) ? $test->account : Account::factory()->for($user)->create();

    return PlannedTransaction::factory()->for($user)->for($account)->create([
        'description' => $name, 'amount' => $cents, 'direction' => TransactionDirection::Debit, 'type' => PlannedTransactionType::Bill,
        'frequency' => RecurrenceFrequency::DontRepeat, 'start_date' => $date, 'until_date' => null,
        'origin' => PlanOrigin::Declared, 'status' => PlannedTransactionStatus::Active, 'merchant_key' => null, ...$attributes,
    ]);
}

it('returns an empty plan when no income is declared', function () {
    payBill($this, 'Rent', 10000, '2026-03-20');
    payIncome($this, 'Detected pay', 160000, '2026-03-20', ['origin' => PlanOrigin::Detected]);
    payIncome($this, 'Paused pay', 160000, '2026-03-20', ['status' => PlannedTransactionStatus::Inactive]);
    payIncome($this, 'One off', 160000, '2026-03-20', ['frequency' => RecurrenceFrequency::DontRepeat]);

    $plan = $this->planner->plan($this->user);

    expect($plan->periods)->toBe([])->and($plan->needsIncome)->toBeTrue()
        ->and(collect($plan->ignoredIncome)->pluck('name', 'reason')->all())->toHaveCount(3)
        ->and(collect($plan->ignoredIncome)->pluck('name')->sort()->values()->all())->toBe(['Detected pay', 'One off', 'Paused pay']);
});

it('reserves the uncovered part of a bill from the previous paycheck', function () {
    payIncome($this, 'Salary', 160000, '2026-03-20');
    payBill($this, 'Big bill', 200000, '2026-04-04');

    $plan = $this->planner->plan($this->user, 2);
    [$bridge, $first, $second] = $plan->periods;

    expect($plan->needsIncome)->toBeFalse()->and($plan->periods)->toHaveCount(3)
        ->and($first->income)->toBe(160000)->and($first->reserved)->toBe(40000)->and($first->obligation)->toBe(40000)->and($first->safeToSpend)->toBe(120000)
        ->and($second->billsDue)->toBe(200000)->and($second->fundedFromEarlier)->toBe(40000)->and($second->obligation)->toBe(160000)->and($second->shortfall)->toBe(0)->and($second->safeToSpend)->toBe(0)
        ->and($second->largestName)->toBe('Big bill')->and($second->largestAmount)->toBe(200000);
});

it('puts what no paycheck can cover on the earliest paycheck as a shortfall', function () {
    payIncome($this, 'Salary', 100000, '2026-03-20');
    payBill($this, 'Huge', 500000, '2026-04-04');

    [, $first, $second] = $this->planner->plan($this->user, 2)->periods;

    expect($first->reserved)->toBe(400000)->and($first->shortfall)->toBe(300000)->and($first->safeToSpend)->toBe(0)->and($second->fundedFromEarlier)->toBe(400000)->and($second->shortfall)->toBe(0);
});

it('takes reserves from the nearest earlier paycheck first and spills to earlier ones', function () {
    payIncome($this, 'Salary', 100000, '2026-03-20');
    payBill($this, 'Mid', 30000, '2026-04-04');
    payBill($this, 'Late', 150000, '2026-04-18');

    [, $first, $second, $third] = $this->planner->plan($this->user, 3)->periods;

    expect($third->fundedFromEarlier)->toBe(50000)->and($second->reserved)->toBe(50000)->and($first->reserved)->toBe(0)->and($third->shortfall)->toBe(0);

    payBill($this, 'Later', 120000, '2026-04-19');

    [, $first, $second, $third] = $this->planner->plan($this->user, 3)->periods;

    expect($second->reserved)->toBe(70000)->and($first->reserved)->toBe(100000)->and($third->fundedFromEarlier)->toBe(170000)->and($third->shortfall)->toBe(0)->and($first->safeToSpend)->toBe(0);
});

it('measures the bridge window against cash on hand', function () {
    payIncome($this, 'Salary', 160000, '2026-03-25');
    payBill($this, 'Phone', 6000, '2026-03-18');
    $this->account->update(['balance' => 4000]);

    $bridge = $this->planner->plan($this->user)->periods[0];

    expect($bridge->index)->toBe(0)->and($bridge->billsDue)->toBe(6000)->and($bridge->shortfall)->toBe(2000)->and($bridge->safeToSpend)->toBe(0);

    $this->account->update(['balance' => 50000]);

    $bridge = $this->planner->plan($this->user)->periods[0];

    expect($bridge->shortfall)->toBe(0)->and($bridge->safeToSpend)->toBe(44000);
});

it('makes no claim about the bridge window when cash is unknown', function () {
    payIncome($this, 'Salary', 160000, '2026-03-25');
    payBill($this, 'Phone', 6000, '2026-03-18');
    Account::query()->where('user_id', $this->user->id)->update(['is_tracked' => false]);

    $plan = $this->planner->plan($this->user);

    expect($plan->cashOnHand)->toBeNull()->and($plan->periods[0]->safeToSpend)->toBeNull()->and($plan->periods[0]->shortfall)->toBe(0);
});

it('leaves credit cards and untracked accounts out of cash on hand', function () {
    payIncome($this, 'Salary', 160000, '2026-03-25');
    Account::factory()->for($this->user)->creditCard()->create(['balance' => 999999]);
    Account::factory()->for($this->user)->create(['balance' => 777777, 'is_tracked' => false]);

    expect($this->planner->plan($this->user)->cashOnHand)->toBe(50000);
});

it('assigns a snoozed bill to the period of its snoozed date', function () {
    payIncome($this, 'Salary', 160000, '2026-03-20');
    $plan = payBill($this, 'Gym', 3000, '2026-03-22');
    PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-03-22', 'due_date' => '2026-03-22', 'snoozed_until' => '2026-04-06']);

    [, $first, $second] = $this->planner->plan($this->user, 2)->periods;

    expect($first->billsDue)->toBe(0)->and($second->billsDue)->toBe(3000);
});

it('drops a bill snoozed beyond the plan', function () {
    payIncome($this, 'Salary', 160000, '2026-03-20');
    $plan = payBill($this, 'Gym', 3000, '2026-03-22');
    PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-03-22', 'due_date' => '2026-03-22', 'snoozed_until' => '2026-09-01']);

    expect(collect($this->planner->plan($this->user, 2)->periods)->sum('billsDue'))->toBe(0);
});

it('lands a past due bill whole in the first period', function () {
    payIncome($this, 'Salary', 160000, '2026-03-20');
    $plan = payBill($this, 'Late', 8000, '2026-03-10');
    $occurrence = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-03-10', 'due_date' => '2026-03-10', 'status' => OccurrenceStatus::Scheduled]);
    PlannedPayment::factory()->create(['planned_occurrence_id' => $occurrence->id, 'transaction_id' => null, 'amount' => 3000, 'paid_on' => '2026-03-12']);

    $bridge = $this->planner->plan($this->user)->periods[0];

    expect($bridge->billsDue)->toBe(5000)->and($bridge->billCount)->toBe(1);
});

it('leaves paid bills out of the plan', function () {
    payIncome($this, 'Salary', 160000, '2026-03-20');
    $plan = payBill($this, 'Paid', 8000, '2026-03-18');
    PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-03-18', 'due_date' => '2026-03-18', 'status' => OccurrenceStatus::Paid, 'closed_at' => now()]);

    expect($this->planner->plan($this->user)->periods[0]->billsDue)->toBe(0);
});

it('sums same day paychecks into one event listed by name', function () {
    payIncome($this, 'Salary', 160000, '2026-03-20');
    payIncome($this, 'Side job', 20000, '2026-03-20');

    [, $first] = $this->planner->plan($this->user, 1)->periods;

    expect($first->income)->toBe(180000)->and($first->sources)->toBe(['Salary', 'Side job']);
});

it('plans three paychecks by default and at most six', function () {
    payIncome($this, 'Salary', 160000, '2026-03-20');

    expect($this->planner->plan($this->user)->periods)->toHaveCount(4)
        ->and($this->planner->plan($this->user, 99)->periods)->toHaveCount(7)
        ->and($this->planner->plan($this->user, 0)->periods)->toHaveCount(2);
});

it('plans only the users own income and bills', function () {
    $other = User::factory()->create();
    payIncome($this, 'Their salary', 900000, '2026-03-17', [], $other);
    payBill($this, 'Their rent', 400000, '2026-03-18', [], $other);
    payIncome($this, 'My salary', 160000, '2026-03-20');
    payBill($this, 'My bill', 5000, '2026-03-18');

    $plan = $this->planner->plan($this->user, 1);

    expect($plan->periods[1]->income)->toBe(160000)->and($plan->periods[1]->sources)->toBe(['My salary'])->and($plan->periods[0]->billsDue)->toBe(5000)->and($plan->periods[0]->largestName)->toBe('My bill');

    expect($this->planner->plan(User::factory()->create())->needsIncome)->toBeTrue();
});

it('leaves paused bills and plain transfers out of the bills', function () {
    payIncome($this, 'Salary', 160000, '2026-03-20');
    payBill($this, 'Paused', 5000, '2026-03-18', ['status' => PlannedTransactionStatus::Inactive]);
    payBill($this, 'Sweep', 5000, '2026-03-18', ['transfer_to_account_id' => Account::factory()->for($this->user)->create()->id]);
    payBill($this, 'Card', 7000, '2026-03-18', ['transfer_to_account_id' => Account::factory()->for($this->user)->creditCard()->create()->id]);

    expect($this->planner->plan($this->user)->periods[0]->billsDue)->toBe(7000);
});

it('reports a shortfall for an uncovered bill in the first period itself', function () {
    payIncome($this, 'Salary', 100000, '2026-03-20');
    payBill($this, 'Huge', 130000, '2026-03-21');

    [, $first] = $this->planner->plan($this->user, 2)->periods;

    expect($first->shortfall)->toBe(30000)->and($first->obligation)->toBe(130000)->and($first->safeToSpend)->toBe(0)->and($first->reserved)->toBe(0);
});

it('leaves out the bridge period when the first payday is today', function () {
    payIncome($this, 'Salary', 160000, '2026-03-15');
    payBill($this, 'Today bill', 5000, '2026-03-15');

    $plan = $this->planner->plan($this->user, 2);

    expect($plan->periods)->toHaveCount(2)->and($plan->periods[0]->index)->toBe(1)->and($plan->periods[0]->billsDue)->toBe(5000)->and($plan->periods[0]->startsOn->toDateString())->toBe('2026-03-15');
});

it('converts bills and income to the base currency when multi-currency is on', function () {
    config(['budget.multi_currency' => true, 'services.exchange_rates.enabled' => false]);
    $this->user->update(['base_currency' => 'USD']);
    $this->account->update(['currency' => 'USD', 'balance' => 20000]);
    $euro = Account::factory()->for($this->user)->create(['currency' => 'EUR', 'is_tracked' => false]);
    App\Models\ExchangeRate::factory()->create(['from_currency' => 'EUR', 'to_currency' => 'USD', 'rate_date' => '2026-03-15', 'rate' => 1.5]);
    payIncome($this, 'Salary', 160000, '2026-03-25');
    $bill = payBill($this, 'Euro bill', 10000, '2026-03-18');
    $bill->update(['account_id' => $euro->id]);

    $bridge = $this->planner->plan($this->user)->periods[0];

    expect($bridge->billsDue)->toBe(15000)->and($bridge->safeToSpend)->toBe(5000);
});
