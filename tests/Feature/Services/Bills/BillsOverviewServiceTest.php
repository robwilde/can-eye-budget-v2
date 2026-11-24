<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountClass;
use App\Enums\OccurrenceState;
use App\Enums\PlannedTransactionStatus;
use App\Enums\PlannedTransactionType;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedOccurrence;
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\PlannedTransactionPriceChange;
use App\Models\User;
use App\Services\Bills\BillsOverviewService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->service = app(BillsOverviewService::class);
});

function billPlan(object $test, string $name, int $cents, array $attributes = []): PlannedTransaction
{
    return PlannedTransaction::factory()->for($test->user)->for($test->account)->create([
        'description' => $name, 'amount' => $cents, 'direction' => TransactionDirection::Debit, 'type' => PlannedTransactionType::Bill,
        'frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2026-01-01', 'until_date' => null, 'merchant_key' => null, ...$attributes,
    ]);
}

function billOccurrence(PlannedTransaction $plan, string $date, array $attributes = [], int $paid = 0): PlannedOccurrence
{
    $occurrence = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => $date, 'due_date' => $date, ...$attributes]);

    if ($paid > 0) {
        PlannedPayment::factory()->create(['planned_occurrence_id' => $occurrence->id, 'transaction_id' => null, 'amount' => $paid, 'paid_on' => min($date, '2026-03-14')]);
    }

    return $occurrence;
}

function billNames(array $rows): array
{
    return array_map(fn ($row): string => $row->name, $rows);
}

it('lists only payable plans in the overview', function () {
    $bill = billPlan($this, 'Rent', 10000);
    $income = billPlan($this, 'Salary', 200000, ['direction' => TransactionDirection::Credit, 'type' => PlannedTransactionType::Income]);
    $savings = Account::factory()->for($this->user)->create(['type' => AccountClass::Savings]);
    $transfer = billPlan($this, 'Sweep', 5000, ['transfer_to_account_id' => $savings->id]);
    $typedIncomeDebit = billPlan($this, 'Odd', 500, ['type' => PlannedTransactionType::Income]);
    foreach ([$bill, $income, $transfer, $typedIncomeDebit] as $plan) {
        billOccurrence($plan, '2026-03-20');
    }

    expect(billNames($this->service->overview($this->user)->thisMonth))->toBe(['Rent']);
});

it('lists a recurring credit card payment as a bill but not a budget reserve', function () {
    $card = Account::factory()->for($this->user)->creditCard()->create();
    $category = Category::factory()->for($this->user)->create();
    $payment = billPlan($this, 'Card payment', 20000, ['transfer_to_account_id' => $card->id, 'category_id' => $category->id]);
    $groceries = billPlan($this, 'Gym', 3000, ['category_id' => $category->id]);
    billOccurrence($payment, '2026-03-20');
    billOccurrence($groceries, '2026-03-21');

    $overview = $this->service->overview($this->user);

    expect(billNames($overview->thisMonth))->toBe(['Card payment', 'Gym'])
        ->and($this->service->billsReserved($this->user, [$category->id], CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')))->toBe(3000);
});

it('normalises subscriptions to monthly and annual equivalents', function () {
    billPlan($this, 'Netflix', 1000, ['type' => PlannedTransactionType::Subscription]);
    billPlan($this, 'Domain', 12000, ['type' => PlannedTransactionType::Subscription, 'frequency' => RecurrenceFrequency::EveryYear]);
    billPlan($this, 'Paused', 9999, ['type' => PlannedTransactionType::Subscription, 'status' => PlannedTransactionStatus::Inactive]);
    billPlan($this, 'Rent', 50000);

    $rollup = $this->service->subscriptions($this->user);

    expect($rollup->activeCount)->toBe(2)->and($rollup->monthlyEquivalent)->toBe(2000)->and($rollup->annualEquivalent)->toBe(24000);
});

it('normalises weekly and fortnightly subscriptions by their real frequency', function () {
    billPlan($this, 'Weekly', 1000, ['type' => PlannedTransactionType::Subscription, 'frequency' => RecurrenceFrequency::EveryWeek]);

    expect($this->service->subscriptions($this->user)->annualEquivalent)->toBe((int) round(1000 * 365.25 / 7));
});

it('lists the ten most recent price changes of the last twelve months', function () {
    $plan = billPlan($this, 'Netflix', 1000, ['type' => PlannedTransactionType::Subscription]);
    foreach (range(1, 12) as $i) {
        PlannedTransactionPriceChange::factory()->create(['planned_transaction_id' => $plan->id, 'effective_on' => CarbonImmutable::parse('2026-03-01')->subDays($i * 10)->toDateString()]);
    }

    $changes = $this->service->subscriptions($this->user)->recentPriceChanges;

    expect($changes)->toHaveCount(10)->and($changes->first()->effective_on->toDateString())->toBe('2026-02-19');
});

it('leaves price changes older than twelve months out of the rollup', function () {
    $plan = billPlan($this, 'Netflix', 1000, ['type' => PlannedTransactionType::Subscription]);
    foreach (['2026-02-01', '2025-04-01', '2025-03-10', '2024-01-01'] as $date) {
        PlannedTransactionPriceChange::factory()->create(['planned_transaction_id' => $plan->id, 'effective_on' => $date]);
    }

    expect($this->service->subscriptions($this->user)->recentPriceChanges->map(fn ($change): string => $change->effective_on->toDateString())->all())->toBe(['2026-02-01', '2025-04-01']);
});

it('lists a bill snoozed past month end under later', function () {
    $plan = billPlan($this, 'Water', 8000);
    billOccurrence($plan, '2026-03-20', ['snoozed_until' => '2026-04-05']);

    $overview = $this->service->overview($this->user);

    expect($overview->thisMonth)->toBe([])->and($overview->later)->toHaveCount(1)->and($overview->later[0]->dueOn->toDateString())->toBe('2026-04-05');
});

it('reserves the unpaid remainder of open bills in a category', function () {
    $category = Category::factory()->for($this->user)->create();
    $plan = billPlan($this, 'Groceries bill', 30000, ['category_id' => $category->id]);
    billOccurrence($plan, '2026-03-20', [], 10000);

    expect($this->service->billsReserved($this->user, [$category->id], CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')))->toBe(20000)
        ->and($this->service->billsReserved($this->user, [$category->id + 99], CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')))->toBe(0);
});

it('never includes another users plans in the overview', function () {
    $other = User::factory()->create();
    $theirCategory = Category::factory()->for($other)->create();
    $theirs = PlannedTransaction::factory()->for($other)->for(Account::factory()->for($other))->create(['description' => 'Theirs', 'amount' => 7000, 'direction' => TransactionDirection::Debit, 'type' => PlannedTransactionType::Subscription, 'category_id' => $theirCategory->id, 'merchant_key' => null]);
    billOccurrence($theirs, '2026-03-20');
    billOccurrence($theirs, '2026-03-05');
    PlannedTransactionPriceChange::factory()->create(['planned_transaction_id' => $theirs->id, 'effective_on' => '2026-03-01']);
    $mine = billPlan($this, 'Mine', 1000);
    billOccurrence($mine, '2026-03-18');

    $overview = $this->service->overview($this->user);

    expect(billNames($overview->thisMonth))->toBe(['Mine'])->and($overview->pastDue)->toBe([])->and($overview->kpis->remainingThisMonth)->toBe(1000)
        ->and($this->service->subscriptions($this->user)->recentPriceChanges)->toHaveCount(0)->and($this->service->allBills($this->user)->pluck('name')->all())->toBe(['Mine'])
        ->and($this->service->billsReserved($this->user, [$theirCategory->id], CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')))->toBe(0);
});

it('sorts the sections: past due, this month, later, dormant and next up', function () {
    $due = billPlan($this, 'Overdue one', 1000);
    $soon = billPlan($this, 'Soon', 2000);
    $far = billPlan($this, 'Far', 3000);
    $paused = billPlan($this, 'Paused', 4000, ['status' => PlannedTransactionStatus::Inactive]);
    billOccurrence($due, '2026-03-05');
    billOccurrence($soon, '2026-03-16');
    billOccurrence($far, '2026-04-20');
    billOccurrence($far, '2026-05-20');
    billOccurrence($paused, '2026-03-25');

    $overview = $this->service->overview($this->user);

    expect(billNames($overview->pastDue))->toBe(['Overdue one'])->and(billNames($overview->thisMonth))->toBe(['Soon'])->and(billNames($overview->later))->toBe(['Far'])
        ->and(billNames($overview->dormant))->toBe(['Paused'])->and($overview->dormant[0]->state)->toBe(OccurrenceState::Dormant)
        ->and(billNames($overview->nextUp))->toBe(['Soon', 'Far', 'Far'])
        ->and($overview->monthOverdue)->toBe(1000)->and($overview->monthToCome)->toBe(2000);
});

it('computes the KPIs from stored occurrences and payments', function () {
    $a = billPlan($this, 'A', 10000, ['autopay' => true]);
    $b = billPlan($this, 'B', 20000);
    $c = billPlan($this, 'C', 5000);
    billOccurrence($a, '2026-03-17');
    billOccurrence($b, '2026-03-10', [], 5000);
    billOccurrence($c, '2026-03-28');
    $paid = billOccurrence($a, '2026-03-02', ['status' => App\Enums\OccurrenceStatus::Paid, 'closed_at' => now()], 10000);

    $kpis = $this->service->overview($this->user)->kpis;

    expect($kpis->remainingThisMonth)->toBe(10000 + 15000 + 5000)->and($kpis->paidThisMonth)->toBe(15000)->and($kpis->dueNextSevenDays)->toBe(10000)
        ->and($kpis->pastDueTotal)->toBe(15000)->and($kpis->countOwed)->toBe(3)->and($kpis->countNeedingAction)->toBe(2);
});

it('filters and sorts all bills', function () {
    $gym = billPlan($this, 'Gym', 5000, ['merchant_key' => 'fitco', 'type' => PlannedTransactionType::Subscription]);
    $rent = billPlan($this, 'Rent', 90000);
    $paused = billPlan($this, 'Old magazine', 1500, ['status' => PlannedTransactionStatus::Inactive]);
    $ended = billPlan($this, 'Gone', 700, ['status' => PlannedTransactionStatus::Ended]);
    billOccurrence($gym, '2026-03-10');
    billOccurrence($rent, '2026-03-30', [], 90000 - 1);

    $service = $this->service;
    $user = $this->user;

    expect($service->allBills($user)->pluck('name')->all())->toBe(['Gym', 'Old magazine', 'Rent'])
        ->and($service->allBills($user, 'fit')->pluck('name')->all())->toBe(['Gym'])
        ->and($service->allBills($user, 'GYM')->pluck('name')->all())->toBe(['Gym'])
        ->and($service->allBills($user, null, null, 'paused')->pluck('name')->all())->toBe(['Old magazine'])
        ->and($service->allBills($user, null, null, 'ended')->pluck('name')->all())->toBe(['Gone'])
        ->and($service->allBills($user, null, null, null, PlannedTransactionType::Subscription)->pluck('name')->all())->toBe(['Gym'])
        ->and($service->allBills($user, null, OccurrenceState::PartiallyPaid)->pluck('name')->all())->toBe(['Rent'])
        ->and($service->allBills($user, null, OccurrenceState::Overdue)->pluck('name')->all())->toBe(['Gym'])
        ->and($service->allBills($user, sort: 'amount', descending: true)->pluck('name')->all())->toBe(['Rent', 'Gym', 'Old magazine']);
});

it('treats a search term literally', function () {
    billPlan($this, '100% sure', 1000);
    billPlan($this, 'Plain', 1000);

    expect($this->service->allBills($this->user, '%')->pluck('name')->all())->toBe(['100% sure']);
});

it('counts every listed day of the month when annualising a subscription', function () {
    billPlan($this, 'Twice', 1000, ['type' => PlannedTransactionType::Subscription, 'days_of_month' => [1, 15]]);

    expect($this->service->subscriptions($this->user)->annualEquivalent)->toBe(24000);
});

it('leaves ended subscriptions out of the rollup', function () {
    billPlan($this, 'Done', 1000, ['type' => PlannedTransactionType::Subscription, 'until_date' => '2026-02-01']);
    billPlan($this, 'Live', 1000, ['type' => PlannedTransactionType::Subscription]);

    expect($this->service->subscriptions($this->user)->activeCount)->toBe(1);
});

it('lists a bill paid this month under this month and finds paid bills in all bills', function () {
    $plan = billPlan($this, 'Rent', 10000);
    billOccurrence($plan, '2026-03-05', ['status' => App\Enums\OccurrenceStatus::Paid, 'closed_at' => now()], 10000);
    $open = billPlan($this, 'Gym', 3000);
    billOccurrence($open, '2026-03-20');

    $overview = $this->service->overview($this->user);

    expect(billNames($overview->thisMonth))->toBe(['Rent', 'Gym'])->and($overview->thisMonth[0]->state)->toBe(OccurrenceState::Paid)->and($overview->monthPaid)->toBe(10000)->and($overview->monthToCome)->toBe(3000)
        ->and($this->service->allBills($this->user, null, OccurrenceState::Paid)->pluck('name')->all())->toBe(['Rent']);
});

it('does not reserve a transfer that brings money in', function () {
    $untracked = Account::factory()->for($this->user)->create(['is_tracked' => false]);
    $category = Category::factory()->for($this->user)->create();
    $in = PlannedTransaction::factory()->for($this->user)->for($untracked)->create(['description' => 'Pay card', 'amount' => 9000, 'direction' => TransactionDirection::Debit, 'type' => PlannedTransactionType::Bill, 'transfer_to_account_id' => $this->account->id, 'category_id' => $category->id, 'frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2026-01-01', 'until_date' => null]);
    billOccurrence($in, '2026-03-20');

    expect($this->service->billsReserved($this->user, [$category->id], CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')))->toBe(0);
});
