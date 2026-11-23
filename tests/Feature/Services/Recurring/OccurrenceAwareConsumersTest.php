<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Livewire\Dashboard;
use App\Livewire\PlannedTransactionManager;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPlan;
use App\Models\Category;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\Budgets\BudgetPlanCalculator;
use App\Services\Projection\MonthlyProjectionService;
use App\Services\Recurring\OccurrenceSchedule;
use App\Services\Reports\ReportAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));
    $this->user = User::factory()->create(['next_pay_date' => '2026-03-31', 'pay_amount' => 300000, 'pay_frequency' => App\Enums\PayFrequency::Monthly]);
    $this->account = Account::factory()->for($this->user)->create(['balance' => 500000]);
    $this->user->update(['primary_account_id' => $this->account->id]);
});

function occAwarePlan(object $test, array $plan = [], ?array $row = null): PlannedTransaction
{
    $planned = PlannedTransaction::factory()->for($test->user)->for($test->account)->create(array_merge([
        'direction' => TransactionDirection::Debit,
        'amount' => 10000,
        'start_date' => '2026-03-20',
        'frequency' => RecurrenceFrequency::EveryMonth,
        'until_date' => null,
    ], $plan));

    if ($row !== null) {
        PlannedOccurrence::factory()->for($planned, 'plannedTransaction')->create(array_merge(['original_date' => '2026-03-20', 'due_date' => '2026-03-20'], $row));
    }

    return $planned;
}

function occAwareSkipped(): array
{
    return ['status' => OccurrenceStatus::Skipped, 'closed_at' => now(), 'closed_manually' => true];
}

function occAwareDates(array $items): array
{
    return array_map(fn ($item): string => $item->date->toDateString().':'.$item->amount, $items);
}

function occAwareRange(object $test, PlannedTransaction $plan, string $from, string $to): array
{
    return occAwareDates((new OccurrenceSchedule)->between(collect([$plan]), CarbonImmutable::parse($from), CarbonImmutable::parse($to))[$plan->id]);
}

it('reads computed dates at the plan amount when nothing is stored', function () {
    $plan = occAwarePlan($this);

    expect(occAwareRange($this, $plan, '2026-03-01', '2026-05-31'))->toBe(['2026-03-20:10000', '2026-04-20:10000', '2026-05-20:10000'])
        ->and((new OccurrenceSchedule)->next($plan, CarbonImmutable::parse('2026-03-10'))->date->toDateString())->toBe('2026-03-20');
});

it('leaves a skipped occurrence out of the schedule', function () {
    $plan = occAwarePlan($this, row: occAwareSkipped());

    expect(occAwareRange($this, $plan, '2026-03-01', '2026-04-30'))->toBe(['2026-04-20:10000']);
});

it('puts a snoozed occurrence at its snooze date, also when it moved in from outside the range', function () {
    $plan = occAwarePlan($this, ['start_date' => '2026-02-20'], ['original_date' => '2026-02-20', 'due_date' => '2026-02-20', 'snoozed_until' => '2026-03-04']);

    expect(occAwareRange($this, $plan, '2026-03-01', '2026-03-31'))->toBe(['2026-03-04:10000', '2026-03-20:10000'])
        ->and(occAwareRange($this, $plan, '2026-02-01', '2026-02-28'))->toBe([]);
});

it('drops an occurrence snoozed out of the range', function () {
    $plan = occAwarePlan($this, row: ['snoozed_until' => '2026-04-02']);

    expect(occAwareRange($this, $plan, '2026-03-01', '2026-03-31'))->toBe([])
        ->and(occAwareRange($this, $plan, '2026-04-01', '2026-04-30'))->toBe(['2026-04-02:10000', '2026-04-20:10000']);
});

it('uses the pinned amount of an occurrence everywhere plan amounts are read', function () {
    $plan = occAwarePlan($this, row: ['expected_amount' => 7000]);

    expect(occAwareRange($this, $plan, '2026-03-01', '2026-04-30'))->toBe(['2026-03-20:7000', '2026-04-20:10000']);
});

it('leaves a skipped occurrence out of the balance projection', function () {
    occAwarePlan($this, row: occAwareSkipped());

    $dates = collect(app(MonthlyProjectionService::class)->forUser($this->user->fresh(), 2)->points)->map(fn ($point) => $point->date->toDateString());

    expect($dates->all())->toBe(['2026-03-10', '2026-04-20']);
});

it('moves a snoozed occurrence to its snooze date and uses its pinned amount in the balance projection', function () {
    occAwarePlan($this, row: ['snoozed_until' => '2026-03-25', 'expected_amount' => 7000]);

    $points = app(MonthlyProjectionService::class)->forUser($this->user->fresh(), 1)->points;

    expect($points[1]->date->toDateString())->toBe('2026-03-25')
        ->and($points[1]->eventAmountCents)->toBe(-7000);
});

it('leaves skipped, snoozed past payday and pinned occurrences right in the total needed until payday', function () {
    $skipped = occAwarePlan($this, ['frequency' => RecurrenceFrequency::DontRepeat], occAwareSkipped());
    occAwarePlan($this, ['frequency' => RecurrenceFrequency::DontRepeat, 'start_date' => '2026-03-21'], ['original_date' => '2026-03-21', 'due_date' => '2026-03-21', 'snoozed_until' => '2026-04-05']);
    occAwarePlan($this, ['frequency' => RecurrenceFrequency::DontRepeat, 'start_date' => '2026-03-22', 'amount' => 20000], ['original_date' => '2026-03-22', 'due_date' => '2026-03-22', 'expected_amount' => 3000]);
    occAwarePlan($this, ['frequency' => RecurrenceFrequency::DontRepeat, 'start_date' => '2026-03-23', 'amount' => 5000], ['original_date' => '2026-03-23', 'due_date' => '2026-03-23', 'snoozed_until' => '2026-03-28']);

    expect($skipped->occurrences()->count())->toBe(1)
        ->and($this->user->fresh()->totalNeededUntilPayday())->toBe(8000);
});

it('counts a snoozed occurrence in the period it moved to', function () {
    occAwarePlan($this, row: ['snoozed_until' => '2026-04-02', 'expected_amount' => 7000]);
    $march = app(ReportAggregator::class)->atoms($this->user, 'plan', CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));
    $both = collect(app(ReportAggregator::class)->atoms($this->user, 'plan', CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-04-30')));

    expect($march)->toBe([])
        ->and($both->firstWhere('ym', '2026-04')['total'])->toBe(17000)
        ->and($both->firstWhere('ym', '2026-04')['count'])->toBe(2);
});

it('leaves a skipped occurrence out of the plan figures', function () {
    occAwarePlan($this, row: occAwareSkipped());

    $atoms = collect(app(ReportAggregator::class)->atoms($this->user, 'plan', CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-04-30')));

    expect($atoms->pluck('ym')->all())->toBe(['2026-04']);
});

function occAwareBudget(object $test, Category $category): BudgetPlan
{
    $plan = BudgetPlan::factory()->for($test->user)->create([
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'total_amount' => 0,
        'expected_income' => 0,
        'initialised_at' => '2026-03-01',
    ]);
    $line = Budget::factory()->inPlan($plan)->withCategory($category)->create(['limit_amount' => 50000]);

    return tap($plan, fn () => $test->budgetLineId = $line->id);
}

it('reserves no bill for a skipped occurrence and counts a snoozed one in the period it moved to', function () {
    $category = Category::factory()->for($this->user)->create();
    occAwarePlan($this, ['category_id' => $category->id], occAwareSkipped());
    occAwarePlan($this, ['category_id' => $category->id, 'frequency' => RecurrenceFrequency::DontRepeat, 'start_date' => '2026-02-18', 'amount' => 4000], ['original_date' => '2026-02-18', 'due_date' => '2026-02-18', 'snoozed_until' => '2026-03-14']);
    occAwarePlan($this, ['category_id' => $category->id, 'frequency' => RecurrenceFrequency::DontRepeat, 'start_date' => '2026-03-26', 'amount' => 9000], ['original_date' => '2026-03-26', 'due_date' => '2026-03-26', 'snoozed_until' => '2026-04-03']);
    occAwarePlan($this, ['category_id' => $category->id, 'frequency' => RecurrenceFrequency::DontRepeat, 'start_date' => '2026-03-27', 'amount' => 6000], ['original_date' => '2026-03-27', 'due_date' => '2026-03-27', 'expected_amount' => 2500]);
    $plan = occAwareBudget($this, $category);

    expect(app(BudgetPlanCalculator::class)->drilldown($plan, $this->budgetLineId)->billsReserved)->toBe(6500);
});

it('shows the next open occurrence after a skipped one', function () {
    $first = occAwarePlan($this, row: occAwareSkipped());
    $second = occAwarePlan($this, ['description' => 'Snoozed one', 'start_date' => '2026-03-12', 'frequency' => RecurrenceFrequency::DontRepeat], ['original_date' => '2026-03-12', 'due_date' => '2026-03-12', 'snoozed_until' => '2026-03-30']);

    $upcoming = Livewire::actingAs($this->user)->test(Dashboard::class)->instance()->nextThreePlanned();

    expect($upcoming->map(fn (array $row): string => $row['planned']->id.':'.$row['next']->toDateString())->all())
        ->toBe([$second->id.':2026-03-30', $first->id.':2026-04-20']);
});

it('shows the next open occurrence after a skipped one in the plan list', function () {
    occAwarePlan($this, row: occAwareSkipped());

    Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)
        ->assertViewHas('plans', fn ($plans): bool => $plans->first()->next_occurrence->toDateString() === '2026-04-20');
});

it('shows the next date of an end counted plan that has many dates behind it', function () {
    $plan = occAwarePlan($this, ['start_date' => '2025-01-01', 'frequency' => RecurrenceFrequency::EveryWeek, 'end_after_count' => 200]);

    $upcoming = Livewire::actingAs($this->user)->test(Dashboard::class)->instance()->nextThreePlanned();

    expect((new OccurrenceSchedule)->next($plan, CarbonImmutable::parse('2026-03-10'))->date->toDateString())->toBe('2026-03-11')
        ->and($upcoming->first()['next']->toDateString())->toBe('2026-03-11');

    Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)
        ->assertViewHas('plans', fn ($plans): bool => $plans->first()->next_occurrence->toDateString() === '2026-03-11');
});

it('counts an overdue occurrence snoozed forward as the next one', function () {
    $plan = occAwarePlan($this, ['start_date' => '2026-01-05'], ['original_date' => '2026-03-05', 'due_date' => '2026-03-05', 'snoozed_until' => '2026-03-15']);

    $next = (new OccurrenceSchedule)->next($plan, CarbonImmutable::parse('2026-03-10'));

    expect($next->date->toDateString())->toBe('2026-03-15')
        ->and($next->original->toDateString())->toBe('2026-03-05');
});

it('shows the amount of the next occurrence on the dashboard', function () {
    occAwarePlan($this, row: ['expected_amount' => 7000]);

    $upcoming = Livewire::actingAs($this->user)->test(Dashboard::class)->instance()->nextThreePlanned();

    expect($upcoming->first()['amount'])->toBe(7000);
});

it('finds the next occurrence of every plan with one query for the stored rows', function () {
    $first = occAwarePlan($this, row: occAwareSkipped());
    $second = occAwarePlan($this, ['start_date' => '2026-03-12']);

    DB::enableQueryLog();
    $next = (new OccurrenceSchedule)->nextFor(collect([$first, $second]), CarbonImmutable::parse('2026-03-10'));
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(1)
        ->and($next[$first->id]->date->toDateString())->toBe('2026-04-20')
        ->and($next[$second->id]->date->toDateString())->toBe('2026-03-12');
});

it('lets one posting pay only one of two occurrences on the same day in the bills reserved', function () {
    $category = Category::factory()->for($this->user)->create();
    $plan = occAwarePlan($this, [
        'category_id' => $category->id,
        'amount' => 1000,
        'start_date' => '2026-03-03',
        'frequency' => RecurrenceFrequency::EveryWeek,
    ], ['original_date' => '2026-03-03', 'due_date' => '2026-03-03', 'snoozed_until' => '2026-03-10']);
    App\Models\Transaction::factory()->for($this->user)->for($this->account)->create([
        'category_id' => $category->id,
        'amount' => 1000,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-03-10',
        'planned_transaction_id' => $plan->id,
    ]);
    $budget = occAwareBudget($this, $category);

    expect(app(BudgetPlanCalculator::class)->drilldown($budget, $this->budgetLineId)->billsReserved)->toBe(4000);
});
