<?php

declare(strict_types=1);

use App\Enums\InsightPriority;
use App\Enums\InsightStatus;
use App\Enums\InsightType;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Jobs\GenerateInsightsJob;
use App\Models\Account;
use App\Models\AccountDailyBalance;
use App\Models\Budget;
use App\Models\BudgetPlan;
use App\Models\Category;
use App\Models\Goal;
use App\Models\GoalAccount;
use App\Models\Insight;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InsightRecorder;

beforeEach(function () {
    config(['budget.insights_enabled' => true, 'budget.goals_enabled' => true]);
    $this->travelTo('2026-11-20 12:00:00');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create(['balance' => 100000]);
});

function insightsOf(User $user, InsightType $type): Illuminate\Database\Eloquent\Collection
{
    return Insight::query()->where('user_id', $user->id)->where('type', $type)->orderBy('id')->get();
}

function budgetPlan(User $user, string $month): BudgetPlan
{
    $start = Carbon\CarbonImmutable::parse("{$month}-01");

    return BudgetPlan::factory()->create([
        'user_id' => $user->id,
        'period_start' => $start->toDateString(),
        'period_end' => $start->endOfMonth()->toDateString(),
        'initialised_at' => $start,
    ]);
}

function budgetedCategory(User $user, Account $account, BudgetPlan $plan, string $name, int $limit, int $spent): Category
{
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => $name]);
    Budget::factory()->inPlan($plan)->create(['category_id' => $category->id, 'limit_amount' => $limit]);

    if ($spent > 0) {
        Transaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $category->id,
            'amount' => $spent,
            'post_date' => $plan->period_start->addDays(4)->toDateString(),
        ]);
    }

    return $category;
}

function seriesRow(Account $account, string $date, int $balance): AccountDailyBalance
{
    return AccountDailyBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => $date,
        'start_balance' => $balance,
        'end_balance' => $balance,
    ]);
}

it('raises a high budget-at-risk insight naming up to three categories', function () {
    $plan = budgetPlan($this->user, '2026-11');

    foreach (['Groceries', 'Dining', 'Transport', 'Fuel'] as $name) {
        budgetedCategory($this->user, $this->account, $plan, $name, 10000, 15000);
    }

    GenerateInsightsJob::dispatchSync($this->user);

    $insight = insightsOf($this->user, InsightType::BudgetAtRisk)->sole();

    expect($insight->priority)->toBe(InsightPriority::High)
        ->and($insight->dedup_key)->toBe('budget_at_risk:2026-11')
        ->and($insight->figures['categories'])->toHaveCount(3)
        ->and($insight->figures['flagged_count'])->toBe(4)
        ->and($insight->figures['over_count'])->toBe(4);
});

it('raises a medium budget-at-risk insight at 90 percent without any category over', function () {
    $plan = budgetPlan($this->user, '2026-11');
    budgetedCategory($this->user, $this->account, $plan, 'Groceries', 10000, 9000);
    budgetedCategory($this->user, $this->account, $plan, 'Dining', 10000, 8999);

    GenerateInsightsJob::dispatchSync($this->user);

    $insight = insightsOf($this->user, InsightType::BudgetAtRisk)->sole();

    expect($insight->priority)->toBe(InsightPriority::Medium)
        ->and($insight->figures['categories'])->toBe(['Groceries'])
        ->and($insight->comparison['over_count'])->toBe(0)
        ->and($insight->comparison['near_count'])->toBe(1)
        ->and(insightsOf($this->user, InsightType::BudgetOnTrack))->toHaveCount(0);
});

it('resurfaces an acknowledged budget-at-risk insight when a different category becomes flagged', function () {
    $plan = budgetPlan($this->user, '2026-11');
    $groceries = budgetedCategory($this->user, $this->account, $plan, 'Groceries', 10000, 15000);
    $dining = budgetedCategory($this->user, $this->account, $plan, 'Dining', 10000, 5000);

    GenerateInsightsJob::dispatchSync($this->user);
    $insight = insightsOf($this->user, InsightType::BudgetAtRisk)->sole();
    app(InsightRecorder::class)->acknowledge($insight);

    Transaction::query()->where('category_id', $groceries->id)->update(['amount' => 5000]);
    Transaction::query()->where('category_id', $dining->id)->update(['amount' => 15000]);
    GenerateInsightsJob::dispatchSync($this->user);

    $insight->refresh();

    expect($insight->status)->toBe(InsightStatus::Active)
        ->and($insight->figures['categories'])->toBe(['Dining'])
        ->and($insight->comparison['categories'])->toBe([$dining->id => 1]);
});

it('creates a budget-on-track insight only after mid-month', function () {
    $plan = budgetPlan($this->user, '2026-11');
    budgetedCategory($this->user, $this->account, $plan, 'Groceries', 100000, 1000);

    $this->travelTo('2026-11-10 12:00:00');
    GenerateInsightsJob::dispatchSync($this->user);

    expect(insightsOf($this->user, InsightType::BudgetOnTrack))->toHaveCount(0);

    $this->travelTo('2026-11-20 12:00:00');
    GenerateInsightsJob::dispatchSync($this->user);

    $insight = insightsOf($this->user, InsightType::BudgetOnTrack)->sole();

    expect($insight->dedup_key)->toBe('budget_on_track:2026-11')
        ->and($insight->priority)->toBe(InsightPriority::Low);
});

it('skips budget-on-track when the plan has no budgeted top-level category', function () {
    budgetPlan($this->user, '2026-11');

    GenerateInsightsJob::dispatchSync($this->user);

    expect(insightsOf($this->user, InsightType::BudgetOnTrack))->toHaveCount(0)
        ->and(insightsOf($this->user, InsightType::BudgetAtRisk))->toHaveCount(0);
});

it('ignores a budget plan that is not initialised or not current', function () {
    $draft = budgetPlan($this->user, '2026-11');
    $draft->update(['initialised_at' => null]);
    budgetedCategory($this->user, $this->account, $draft, 'Groceries', 10000, 15000);
    $past = budgetPlan($this->user, '2026-10');
    budgetedCategory($this->user, $this->account, $past, 'Dining', 10000, 15000);

    GenerateInsightsJob::dispatchSync($this->user);

    expect(insightsOf($this->user, InsightType::BudgetAtRisk))->toHaveCount(0);
});

it('never reads another user budget', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->for($other)->create();
    $plan = budgetPlan($other, '2026-11');
    budgetedCategory($other, $otherAccount, $plan, 'Groceries', 10000, 15000);

    GenerateInsightsJob::dispatchSync($this->user);

    expect(insightsOf($this->user, InsightType::BudgetAtRisk))->toHaveCount(0)
        ->and(Insight::query()->where('user_id', $other->id)->count())->toBe(0);
});

it('keys budget insights per month', function () {
    $this->travelTo('2026-07-20 12:00:00');
    $july = budgetPlan($this->user, '2026-07');
    budgetedCategory($this->user, $this->account, $july, 'Groceries', 10000, 15000);
    GenerateInsightsJob::dispatchSync($this->user);
    $julyInsight = insightsOf($this->user, InsightType::BudgetAtRisk)->sole();
    app(InsightRecorder::class)->acknowledge($julyInsight);

    $this->travelTo('2026-08-20 12:00:00');
    $august = budgetPlan($this->user, '2026-08');
    budgetedCategory($this->user, $this->account, $august, 'Dining', 10000, 15000);
    GenerateInsightsJob::dispatchSync($this->user);

    $insights = insightsOf($this->user, InsightType::BudgetAtRisk);
    $augustInsight = $insights->firstWhere('dedup_key', 'budget_at_risk:2026-08');

    expect($insights->pluck('dedup_key')->all())->toBe(['budget_at_risk:2026-07', 'budget_at_risk:2026-08'])
        ->and($augustInsight->status)->toBe(InsightStatus::Active)
        ->and($julyInsight->refresh()->status)->not->toBe(InsightStatus::Active);
});

it('keeps an acknowledged net-worth milestone hidden on later runs', function () {
    seriesRow($this->account, '2026-11-01', 4900000);
    seriesRow($this->account, '2026-11-10', 5100000);

    GenerateInsightsJob::dispatchSync($this->user);

    $milestone = insightsOf($this->user, InsightType::NetWorthMilestone)->sole();
    app(InsightRecorder::class)->acknowledge($milestone);

    $this->travelTo('2026-11-21 12:00:00');
    GenerateInsightsJob::dispatchSync($this->user);

    expect($milestone->dedup_key)->toBe('net_worth_milestone:5000000')
        ->and($milestone->refresh()->status)->toBe(InsightStatus::Acknowledged)
        ->and(insightsOf($this->user, InsightType::NetWorthMilestone))->toHaveCount(1);
});

it('keeps an acknowledged milestone after its crossing leaves the window while unacknowledged ones expire', function () {
    seriesRow($this->account, '2026-10-25', 500000);
    seriesRow($this->account, '2026-11-05', 3000000);
    seriesRow($this->account, '2026-11-10', 5100000);
    GenerateInsightsJob::dispatchSync($this->user);
    $milestones = insightsOf($this->user, InsightType::NetWorthMilestone)->keyBy('dedup_key');
    $active = $milestones['net_worth_milestone:1000000'];
    $read = $milestones['net_worth_milestone:2500000'];
    $acknowledged = $milestones['net_worth_milestone:5000000'];
    $read->update(['status' => InsightStatus::Read, 'read_at' => now()]);
    app(InsightRecorder::class)->acknowledge($acknowledged);

    $this->travelTo('2026-12-30 12:00:00');
    GenerateInsightsJob::dispatchSync($this->user);

    $afterWindow = [$active->refresh()->status, $read->refresh()->status, $acknowledged->refresh()->status];

    seriesRow($this->account, '2026-12-20', 4900000);
    seriesRow($this->account, '2026-12-28', 5100000);
    GenerateInsightsJob::dispatchSync($this->user);

    expect($afterWindow)->toBe([InsightStatus::Expired, InsightStatus::Expired, InsightStatus::Acknowledged])
        ->and($acknowledged->refresh()->status)->toBe(InsightStatus::Acknowledged)
        ->and(insightsOf($this->user, InsightType::NetWorthMilestone))->toHaveCount(3);
});

it('records a milestone for each threshold crossed within the last 30 days', function () {
    seriesRow($this->account, '2026-10-01', 500000);
    seriesRow($this->account, '2026-11-05', 3000000);
    seriesRow($this->account, '2026-11-12', 10500000);

    GenerateInsightsJob::dispatchSync($this->user);

    $milestones = insightsOf($this->user, InsightType::NetWorthMilestone);

    expect($milestones->pluck('dedup_key')->all())->toBe([
        'net_worth_milestone:1000000',
        'net_worth_milestone:2500000',
        'net_worth_milestone:5000000',
        'net_worth_milestone:10000000',
    ])->and($milestones->first()->comparison)->toBe(['threshold_cents' => 1000000]);
});

it('ignores a net-worth crossing older than 30 days', function () {
    seriesRow($this->account, '2026-09-01', 4900000);
    seriesRow($this->account, '2026-09-20', 5100000);

    GenerateInsightsJob::dispatchSync($this->user);

    expect(insightsOf($this->user, InsightType::NetWorthMilestone))->toHaveCount(0);
});

it('counts debt against net worth when finding a crossing', function () {
    $card = Account::factory()->for($this->user)->create(['balance' => -2000000]);
    seriesRow($this->account, '2026-11-01', 6000000);
    seriesRow($card, '2026-11-01', -2000000);
    seriesRow($this->account, '2026-11-10', 6000000);
    seriesRow($card, '2026-11-10', -500000);

    GenerateInsightsJob::dispatchSync($this->user);

    expect(insightsOf($this->user, InsightType::NetWorthMilestone)->pluck('dedup_key')->all())->toBe(['net_worth_milestone:5000000']);
});

it('flags a maintained reserve below its floor', function () {
    $reserve = Goal::factory()->reserve()->for($this->user)->create(['target_amount' => 300000, 'name' => 'Emergency fund']);
    $account = Account::factory()->for($this->user)->create(['balance' => 250000]);
    GoalAccount::factory()->for($reserve)->for($account)->create();

    GenerateInsightsJob::dispatchSync($this->user);

    $insight = insightsOf($this->user, InsightType::GoalDepleted)->sole();

    expect($insight->priority)->toBe(InsightPriority::High)
        ->and($insight->dedup_key)->toBe("goal_depleted:{$reserve->id}")
        ->and($insight->figures['shortfall_cents'])->toBe(50000)
        ->and($insight->comparison)->toBe(['goal_id' => $reserve->id, 'shortfall_band' => 5]);
});

it('skips funded, paused and one-off goals and disabled goals', function () {
    $funded = Goal::factory()->reserve()->for($this->user)->create(['target_amount' => 300000]);
    GoalAccount::factory()->for($funded)->for(Account::factory()->for($this->user)->create(['balance' => 300000]))->create();
    $paused = Goal::factory()->reserve()->paused()->for($this->user)->create(['target_amount' => 300000]);
    GoalAccount::factory()->for($paused)->for(Account::factory()->for($this->user)->create(['balance' => 0]))->create();
    $oneOff = Goal::factory()->for($this->user)->create(['target_amount' => 300000]);
    GoalAccount::factory()->for($oneOff)->for(Account::factory()->for($this->user)->create(['balance' => 0]))->create();

    GenerateInsightsJob::dispatchSync($this->user);

    expect(insightsOf($this->user, InsightType::GoalDepleted))->toHaveCount(0);

    $depleted = Goal::factory()->reserve()->for($this->user)->create(['target_amount' => 300000]);
    GoalAccount::factory()->for($depleted)->for(Account::factory()->for($this->user)->create(['balance' => 0]))->create();
    config(['budget.goals_enabled' => false]);
    GenerateInsightsJob::dispatchSync($this->user);

    expect(insightsOf($this->user, InsightType::GoalDepleted))->toHaveCount(0);
});

it('reports at most the two largest reserve shortfalls', function () {
    $largest = null;

    foreach ([100000, 200000, 300000] as $shortfall) {
        $reserve = Goal::factory()->reserve()->for($this->user)->create(['target_amount' => $shortfall]);
        GoalAccount::factory()->for($reserve)->for(Account::factory()->for($this->user)->create(['balance' => 0]))->create();
        $largest = $reserve;
    }

    GenerateInsightsJob::dispatchSync($this->user);

    $insights = insightsOf($this->user, InsightType::GoalDepleted);

    expect($insights)->toHaveCount(2)
        ->and($insights->pluck('figures.shortfall_cents')->sort()->values()->all())->toBe([200000, 300000])
        ->and($insights->pluck('dedup_key')->all())->toContain("goal_depleted:{$largest->id}");
});

function monthlyBill(User $user, Account $account, string $description, array $overrides = []): PlannedTransaction
{
    return PlannedTransaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => $description,
        'amount' => 1599,
        'direction' => TransactionDirection::Debit,
        'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2026-01-11',
        'is_active' => true,
    ], $overrides));
}

function paidOn(PlannedTransaction $plan, string $date): PlannedOccurrence
{
    return PlannedOccurrence::factory()->paid()->create([
        'planned_transaction_id' => $plan->id,
        'original_date' => $date,
        'due_date' => $date,
    ]);
}

it('flags a recurring item overdue by a full cycle', function () {
    $plan = monthlyBill($this->user, $this->account, 'Streaming Service');
    paidOn($plan, '2026-09-16');

    GenerateInsightsJob::dispatchSync($this->user);

    $insight = insightsOf($this->user, InsightType::SubscriptionAudit)->sole();

    expect($insight->dedup_key)->toBe("subscription_audit:{$plan->id}")
        ->and($insight->figures['last_paid_date'])->toBe('2026-09-16')
        ->and($insight->figures['amount_cents'])->toBe(1599)
        ->and($insight->comparison)->toBe(['planned_transaction_id' => $plan->id, 'cycles_overdue' => 1]);
});

it('does not flag a recurring item that is only a little late or was paid recently', function () {
    $recent = monthlyBill($this->user, $this->account, 'Gym');
    paidOn($recent, '2026-10-21');
    $late = monthlyBill($this->user, $this->account, 'Phone');
    paidOn($late, '2026-10-01');
    $latest = monthlyBill($this->user, $this->account, 'Insurance');
    paidOn($latest, '2026-08-01');
    paidOn($latest, '2026-11-01');

    GenerateInsightsJob::dispatchSync($this->user);

    expect(insightsOf($this->user, InsightType::SubscriptionAudit))->toHaveCount(0);
});

it('leaves out one-off, ended, inactive, income and never-paid plans from the subscription audit', function () {
    foreach ([
        ['One off', ['frequency' => RecurrenceFrequency::DontRepeat]],
        ['Ended', ['until_date' => '2026-10-01']],
        ['Inactive', ['is_active' => false]],
        ['Salary', ['direction' => TransactionDirection::Credit]],
    ] as [$name, $overrides]) {
        paidOn(monthlyBill($this->user, $this->account, $name, $overrides), '2026-08-01');
    }

    monthlyBill($this->user, $this->account, 'Never paid');

    GenerateInsightsJob::dispatchSync($this->user);

    expect(insightsOf($this->user, InsightType::SubscriptionAudit))->toHaveCount(0);
});

it('reports at most the three most overdue recurring items', function () {
    foreach (['2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01'] as $index => $date) {
        paidOn(monthlyBill($this->user, $this->account, "Bill {$index}"), $date);
    }

    GenerateInsightsJob::dispatchSync($this->user);

    $insights = insightsOf($this->user, InsightType::SubscriptionAudit);

    expect($insights)->toHaveCount(3)
        ->and($insights->pluck('figures.last_paid_date')->sort()->values()->all())->toBe(['2026-05-01', '2026-06-01', '2026-07-01']);
});

it('never audits another user recurring item', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->for($other)->create();
    paidOn(monthlyBill($other, $otherAccount, 'Streaming Service'), '2026-08-01');

    GenerateInsightsJob::dispatchSync($this->user);

    expect(Insight::query()->where('type', InsightType::SubscriptionAudit)->where('user_id', $this->user->id)->count())->toBe(0);
});

it('counts a rolled-over budget on a category with no allocation of its own', function () {
    $plan = budgetPlan($this->user, '2026-11');
    $category = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Holidays']);
    Budget::factory()->inPlan($plan)->create([
        'category_id' => $category->id,
        'limit_amount' => 0,
        'rollover_enabled' => true,
        'rolled_over_amount' => 10000,
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
        'amount' => 9500,
        'post_date' => '2026-11-05',
    ]);

    GenerateInsightsJob::dispatchSync($this->user);

    $insight = insightsOf($this->user, InsightType::BudgetAtRisk)->sole();

    expect($insight->figures['categories'])->toBe(['Holidays'])
        ->and(insightsOf($this->user, InsightType::BudgetOnTrack))->toHaveCount(0);
});

it('flags a monthly item exactly when the second scheduled date has passed, whatever the month length', function () {
    $plan = monthlyBill($this->user, $this->account, 'Streaming Service');
    paidOn($plan, '2026-01-01');

    $this->travelTo('2026-02-28 12:00:00');
    GenerateInsightsJob::dispatchSync($this->user);

    expect(insightsOf($this->user, InsightType::SubscriptionAudit))->toHaveCount(0);

    $this->travelTo('2026-03-01 12:00:00');
    GenerateInsightsJob::dispatchSync($this->user);

    expect(insightsOf($this->user, InsightType::SubscriptionAudit)->sole()->comparison['cycles_overdue'])->toBe(1);
});

it('keeps goal and subscription insight titles within the 255 character column', function () {
    $longName = str_repeat('é', 255);
    $reserve = Goal::factory()->reserve()->for($this->user)->create(['target_amount' => 300000, 'name' => $longName]);
    GoalAccount::factory()->for($reserve)->for(Account::factory()->for($this->user)->create(['balance' => 0]))->create();
    paidOn(monthlyBill($this->user, $this->account, $longName), '2026-09-16');

    GenerateInsightsJob::dispatchSync($this->user);

    $goalTitle = insightsOf($this->user, InsightType::GoalDepleted)->sole()->title;
    $subscriptionTitle = insightsOf($this->user, InsightType::SubscriptionAudit)->sole()->title;

    expect(mb_strlen($goalTitle))->toBeLessThanOrEqual(255)
        ->and(mb_strlen($subscriptionTitle))->toBeLessThanOrEqual(255);
});

it('words amounts in the right currency in goal, subscription and net-worth insights', function () {
    $this->user->update(['base_currency' => 'USD']);
    $reserve = Goal::factory()->reserve()->for($this->user)->create(['target_amount' => 300000, 'name' => 'Emergency fund']);
    GoalAccount::factory()->for($reserve)->for(Account::factory()->for($this->user)->create(['balance' => 250000, 'currency' => 'USD']))->create();
    $nzd = Account::factory()->for($this->user)->create(['currency' => 'NZD']);
    paidOn(monthlyBill($this->user, $nzd, 'Streaming Service'), '2026-09-16');
    seriesRow($this->account, '2026-11-01', 4900000);
    seriesRow($this->account, '2026-11-10', 5100000);

    GenerateInsightsJob::dispatchSync($this->user);

    expect(insightsOf($this->user, InsightType::GoalDepleted)->sole()->body)->toContain('USD 2,500.00')
        ->and(insightsOf($this->user, InsightType::SubscriptionAudit)->sole()->body)->toContain('NZD 15.99')
        ->and(insightsOf($this->user, InsightType::NetWorthMilestone)->firstWhere('dedup_key', 'net_worth_milestone:5000000')->title)->toContain('USD 50,000.00');
});

arch('the current budget risk reader uses model queries, not the DB facade')
    ->expect('App\Services\Budgets\CurrentBudgetRisk')
    ->not->toUse('Illuminate\Support\Facades\DB');
