<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\PayFrequency;
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
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\Budgets\BudgetPlanCalculator;
use App\Services\Projection\MonthlyProjectionService;
use App\Services\Recurring\OccurrenceSchedule;
use App\Services\Recurring\OccurrenceStateResolver;
use App\Services\Reports\ReportAggregator;
use App\Support\Calendar\DayActivityLoader;
use App\Support\Calendar\MonthFlowLoader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));
    $this->user = User::factory()->create(['next_pay_date' => '2026-03-31', 'pay_amount' => 300000, 'pay_frequency' => PayFrequency::Monthly]);
    $this->account = Account::factory()->for($this->user)->create(['balance' => 500000]);
    $this->user->update(['primary_account_id' => $this->account->id]);
    $this->category = Category::factory()->for($this->user)->create();
});

function paycPlan(object $test, string $state, array $plan = []): PlannedOccurrence
{
    $planned = PlannedTransaction::factory()->for($test->user)->for($test->account)->create(array_merge([
        'description' => 'Rent',
        'direction' => TransactionDirection::Debit,
        'amount' => 10000,
        'start_date' => '2026-03-20',
        'frequency' => RecurrenceFrequency::EveryMonth,
        'category_id' => $test->category->id,
    ], $plan));

    $occurrence = PlannedOccurrence::factory()->for($planned, 'plannedTransaction')->create([
        'original_date' => '2026-03-20',
        'due_date' => '2026-03-20',
        'status' => $state === 'settled' ? OccurrenceStatus::Paid : OccurrenceStatus::Scheduled,
    ]);

    if ($state !== 'open') {
        PlannedPayment::factory()->create(['planned_occurrence_id' => $occurrence->id, 'amount' => $state === 'settled' ? 10000 : 4000]);
    }

    return $occurrence;
}

it('resolves partially paid and paid from the payments of an occurrence', function () {
    $resolver = new OccurrenceStateResolver;
    $today = $this->user->today();

    expect($resolver->resolve(paycPlan($this, 'partial')->fresh(), $today)->value)->toBe('partially_paid')
        ->and($resolver->resolve(paycPlan($this, 'settled', ['description' => 'Water'])->fresh(), $today)->value)->toBe('paid')
        ->and($resolver->resolve(paycPlan($this, 'open', ['description' => 'Power'])->fresh(), $today)->value)->toBe('upcoming');
});

it('counts the remainder of a partially paid occurrence in the schedule and nothing for a settled one', function () {
    $partial = paycPlan($this, 'partial');
    $settled = paycPlan($this, 'settled', ['description' => 'Water']);
    $schedule = new OccurrenceSchedule;
    $from = CarbonImmutable::parse('2026-03-01');
    $to = CarbonImmutable::parse('2026-03-31');

    $items = $schedule->between(collect([$partial->plannedTransaction, $settled->plannedTransaction]), $from, $to);

    expect($items[$partial->planned_transaction_id][0]->amount)->toBe(6000)
        ->and($items[$partial->planned_transaction_id][0]->paid)->toBe(4000)
        ->and($items[$settled->planned_transaction_id])->toBe([])
        ->and($schedule->next($partial->plannedTransaction, $from)->amount)->toBe(6000)
        ->and($schedule->next($settled->plannedTransaction, $from)->date->toDateString())->toBe('2026-04-20');
});

it('subtracts only the remainder of a partially paid occurrence in the balance projection and nothing when it is settled', function () {
    paycPlan($this, 'partial');

    $points = app(MonthlyProjectionService::class)->forUser($this->user->fresh(), 1)->points;

    expect($points[1]->date->toDateString())->toBe('2026-03-20')->and($points[1]->eventAmountCents)->toBe(-6000);
});

it('leaves a settled occurrence out of the balance projection', function () {
    paycPlan($this, 'settled');

    $dates = collect(app(MonthlyProjectionService::class)->forUser($this->user->fresh(), 1)->points)->map(fn ($point) => $point->date->toDateString())->all();

    expect($dates)->toBe(['2026-03-10']);
});

it('needs only the remainder of a partially paid occurrence until payday and nothing when settled', function () {
    paycPlan($this, 'partial', ['frequency' => RecurrenceFrequency::DontRepeat]);
    expect($this->user->fresh()->totalNeededUntilPayday())->toBe(6000);

    PlannedOccurrence::query()->update(['status' => OccurrenceStatus::Paid]);

    expect($this->user->fresh()->totalNeededUntilPayday())->toBe(0);
});

it('keeps the whole expected amount in the plan figures whether an occurrence is partly or fully paid', function () {
    $partial = paycPlan($this, 'partial');
    $atoms = fn (): array => app(ReportAggregator::class)->atoms($this->user, 'plan', CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

    expect($atoms())->toHaveCount(1)->and($atoms()[0]['total'])->toBe(10000);

    $partial->update(['status' => OccurrenceStatus::Paid]);

    expect($atoms())->toHaveCount(1)->and($atoms()[0]['total'])->toBe(10000);
});

it('reserves only the remainder of a partially paid bill in the budget and nothing when settled', function () {
    $partial = paycPlan($this, 'partial', ['frequency' => RecurrenceFrequency::DontRepeat]);
    $plan = BudgetPlan::factory()->for($this->user)->create(['period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'total_amount' => 0, 'expected_income' => 0, 'initialised_at' => '2026-03-01']);
    $line = Budget::factory()->inPlan($plan)->withCategory($this->category)->create(['limit_amount' => 50000]);
    $reserved = fn (): int => app(BudgetPlanCalculator::class)->drilldown($plan, $line->id)->billsReserved;

    expect($reserved())->toBe(6000);

    $partial->update(['status' => OccurrenceStatus::Paid]);

    expect($reserved())->toBe(0);
});

it('shows a partially paid occurrence as the next bill with its remainder and skips a settled one', function () {
    $partial = paycPlan($this, 'partial');
    $next = fn () => Livewire::actingAs($this->user)->test(Dashboard::class)->instance()->nextThreePlanned()->first();

    expect($next()['amount'])->toBe(6000)->and($next()['next']->toDateString())->toBe('2026-03-20');

    $partial->update(['status' => OccurrenceStatus::Paid]);

    expect($next()['next']->toDateString())->toBe('2026-04-20');
});

it('lists a partially paid occurrence as the next one in the plan list and skips a settled one', function () {
    $partial = paycPlan($this, 'partial');
    $nextOf = fn (): string => Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)->instance()->render()->getData()['plans']->first()->next_occurrence->toDateString();

    expect($nextOf())->toBe('2026-03-20');

    $partial->update(['status' => OccurrenceStatus::Paid]);

    expect($nextOf())->toBe('2026-04-20');
});

it('keeps the whole expected amount in the month plan whether an occurrence is partly or fully paid', function () {
    $partial = paycPlan($this, 'partial');
    $plan = fn (): array => (function () {
        $flow = app(MonthFlowLoader::class)->load($this->user, CarbonImmutable::parse('2026-03-01'), $this->user->today(), 0, 0);

        return [$flow->planIncome, $flow->planExpense];
    })();

    expect($plan())->toBe([0, 10000]);

    $partial->update(['status' => OccurrenceStatus::Paid]);

    expect($plan())->toBe([0, 10000]);
});

it('shows a partially paid occurrence on its day with its state and remainder and hides a settled one', function () {
    $partial = paycPlan($this, 'partial');
    $days = fn (): array => (new DayActivityLoader)->load(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')->endOfDay(), $this->user->id, today: $this->user->today(), overdueFrom: CarbonImmutable::parse('2026-03-01'));

    $pip = $days()['2026-03-20']->pips[0];

    expect($pip->occurrenceState)->toBe('partially_paid')->and($pip->amount)->toBe(6000);

    $partial->update(['status' => OccurrenceStatus::Paid]);

    expect($days())->not->toHaveKey('2026-03-20');
});

it('uses an expected amount of its own less what was paid', function () {
    $occurrence = paycPlan($this, 'partial');
    $occurrence->update(['expected_amount' => 7000]);

    $items = (new OccurrenceSchedule)->between(collect([$occurrence->plannedTransaction]), CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

    expect($items[$occurrence->planned_transaction_id][0]->amount)->toBe(3000);
});

it('reads no more than one query for the payment sums of all occurrences', function () {
    $first = paycPlan($this, 'partial');
    $second = paycPlan($this, 'partial', ['description' => 'Water']);

    DB::enableQueryLog();
    (new OccurrenceSchedule)->between(collect([$first->plannedTransaction, $second->plannedTransaction]), CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));
    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'planned_payments'))->count();
    DB::disableQueryLog();

    expect($queries)->toBe(1);
});

it('does not pair a transaction that paid an occurrence with the next occurrence on the calendar', function () {
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create([
        'description' => 'Coffee', 'amount' => 500, 'direction' => TransactionDirection::Debit,
        'frequency' => RecurrenceFrequency::Everyday, 'start_date' => '2026-03-01',
    ]);
    $occurrence = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-03-10', 'due_date' => '2026-03-10', 'status' => OccurrenceStatus::Paid]);
    PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-03-11', 'due_date' => '2026-03-11']);
    $transaction = App\Models\Transaction::factory()->for($this->user)->for($this->account)->create(['amount' => -500, 'direction' => TransactionDirection::Debit, 'post_date' => '2026-03-10', 'planned_transaction_id' => $plan->id]);
    PlannedPayment::factory()->create(['planned_occurrence_id' => $occurrence->id, 'transaction_id' => $transaction->id, 'amount' => 500]);

    $days = (new DayActivityLoader)->load(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')->endOfDay(), $this->user->id, today: $this->user->today(), overdueFrom: CarbonImmutable::parse('2026-03-01'));

    expect(collect($days['2026-03-11']->pips)->where('kind', 'plan'))->toHaveCount(1);
});

it('shows the remainder of a bill partly paid by a linked transaction on the calendar and in the budget', function () {
    $occurrence = paycPlan($this, 'open', ['frequency' => RecurrenceFrequency::DontRepeat]);
    $transaction = App\Models\Transaction::factory()->for($this->user)->for($this->account)->create(['amount' => -4000, 'direction' => TransactionDirection::Debit, 'post_date' => '2026-03-18', 'planned_transaction_id' => $occurrence->planned_transaction_id, 'category_id' => $this->category->id]);
    PlannedPayment::factory()->create(['planned_occurrence_id' => $occurrence->id, 'transaction_id' => $transaction->id, 'amount' => 4000]);

    $days = (new DayActivityLoader)->load(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')->endOfDay(), $this->user->id, today: $this->user->today(), overdueFrom: CarbonImmutable::parse('2026-03-01'));
    $plan = BudgetPlan::factory()->for($this->user)->create(['period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'total_amount' => 0, 'expected_income' => 0, 'initialised_at' => '2026-03-01']);
    $line = Budget::factory()->inPlan($plan)->withCategory($this->category)->create(['limit_amount' => 50000]);

    expect($days['2026-03-20']->pips[0]->amount)->toBe(6000)
        ->and(app(BudgetPlanCalculator::class)->drilldown($plan, $line->id)->billsReserved)->toBe(6000);
});

it('uses one rule for what an occurrence expects in the schedule, the state and the actions', function () {
    $occurrence = paycPlan($this, 'open', ['amount' => 2000, 'amount_strategy' => App\Enums\PlanAmountStrategy::LastPaid, 'frequency' => RecurrenceFrequency::DontRepeat]);
    $history = PlannedOccurrence::factory()->for($occurrence->plannedTransaction, 'plannedTransaction')->create(['original_date' => '2026-02-20', 'due_date' => '2026-02-20', 'status' => OccurrenceStatus::Paid]);
    PlannedPayment::factory()->create(['planned_occurrence_id' => $history->id, 'amount' => 5000]);
    PlannedPayment::factory()->create(['planned_occurrence_id' => $occurrence->id, 'amount' => 2000]);

    $item = (new OccurrenceSchedule)->between(collect([$occurrence->plannedTransaction]), CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'))[$occurrence->planned_transaction_id][0];

    expect($item->amount)->toBe(3000)
        ->and($occurrence->fresh()->effectiveExpectedAmount())->toBe(5000)
        ->and((new OccurrenceStateResolver)->resolve($occurrence->fresh(), $this->user->today())->value)->toBe('partially_paid');
});

it('looks up the history of every non fixed plan with one query', function () {
    $first = paycPlan($this, 'open', ['amount_strategy' => App\Enums\PlanAmountStrategy::LastPaid]);
    $second = paycPlan($this, 'open', ['description' => 'Power', 'amount_strategy' => App\Enums\PlanAmountStrategy::Average]);

    DB::enableQueryLog();
    (new OccurrenceSchedule)->between(collect([$first->plannedTransaction, $second->plannedTransaction]), CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));
    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'planned_payments'))->count();
    DB::disableQueryLog();

    expect($queries)->toBe(2);
});
