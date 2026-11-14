<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\GoalStatus;
use App\Livewire\GoalDetail;
use App\Livewire\GoalList;
use App\Livewire\MonthlyBudget;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BudgetPlan;
use App\Models\Goal;
use App\Models\GoalAccount;
use App\Models\GoalPledge;
use App\Models\GoalSpend;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo('2026-03-22 12:00:00');
    config(['budget.goals_enabled' => true]);
    $this->user = User::factory()->create();
});

function goalPagesGoal(User $user, array $attributes = [], ?int $balance = null, ?int $earmark = null): Goal
{
    $goal = Goal::factory()->for($user)->create($attributes);

    if ($balance !== null) {
        $account = Account::factory()->for($user)->create(['balance' => $balance]);
        GoalAccount::query()->create(['goal_id' => $goal->id, 'account_id' => $account->id, 'earmark_amount' => $earmark]);
    }

    return $goal;
}

function goalPagesRowNames(User $user): array
{
    return collect(Livewire::actingAs($user)->test(GoalList::class)->instance()->rows)
        ->map(fn (array $row): string => $row['goal']->name)
        ->all();
}

it('returns not found for goals when the flag is off', function () {
    config(['budget.goals_enabled' => false]);
    $goal = goalPagesGoal($this->user);

    $this->actingAs($this->user)->get(route('goals'))->assertNotFound();
    $this->actingAs($this->user)->get(route('goals.show', $goal))->assertNotFound();
    $this->actingAs($this->user)->get(route('dashboard'))->assertDontSee(route('goals'), false);
});

it('requires a signed-in user and shows the goals page and nav link when the flag is on', function () {
    $this->get(route('goals'))->assertRedirect(route('login'));

    $this->actingAs($this->user)->get(route('goals'))
        ->assertOk()
        ->assertSeeLivewire(GoalList::class)
        ->assertSee(route('goals'), false);
});

it('sorts behind goals first and paused goals last', function () {
    $behind = goalPagesGoal($this->user, ['name' => 'Behind goal', 'target_amount' => 500000, 'target_date' => '2026-04-01'], balance: 10000);
    $onTrack = goalPagesGoal($this->user, ['name' => 'On track goal', 'target_amount' => 100000, 'target_date' => '2026-07-20'], balance: 40000);
    goalPagesGoal($this->user, ['name' => 'A paused goal', 'status' => GoalStatus::Paused, 'target_amount' => 500000, 'target_date' => '2026-04-01'], balance: 10000);
    $account = GoalAccount::query()->where('goal_id', $onTrack->id)->firstOrFail()->account;
    Transaction::factory()->for($this->user)->for($account)->credit()->create(['amount' => 60000, 'post_date' => '2026-02-10']);

    expect(goalPagesRowNames($this->user))->toBe(['Behind goal', 'On track goal', 'A paused goal'])
        ->and($behind->exists)->toBeTrue();
});

it('shows goal search only with more than six active goals', function () {
    foreach (range(1, 6) as $i) {
        goalPagesGoal($this->user, ['name' => "Goal $i"]);
    }

    Goal::factory()->for($this->user)->archived()->create(['name' => 'Old goal']);

    Livewire::actingAs($this->user)->test(GoalList::class)->assertDontSeeHtml('data-testid="goal-search"');

    goalPagesGoal($this->user, ['name' => 'Goal 7']);

    Livewire::actingAs($this->user)->test(GoalList::class)->assertSeeHtml('data-testid="goal-search"');
});

it('filters, searches and tucks archived goals into a collapsed section', function () {
    foreach (range(1, 7) as $i) {
        goalPagesGoal($this->user, ['name' => "Goal $i"]);
    }

    goalPagesGoal($this->user, ['name' => 'Resting', 'status' => GoalStatus::Paused]);
    Goal::factory()->for($this->user)->archived()->create(['name' => 'Retired']);

    $page = Livewire::actingAs($this->user)->test(GoalList::class);
    $names = fn () => collect($page->instance()->rows)->map(fn (array $row): string => $row['goal']->name)->all();

    expect($names())->toHaveCount(8)->not->toContain('Retired');

    $page->set('filter', 'paused');
    expect($names())->toBe(['Resting']);

    $page->set('filter', 'all')->set('search', 'goal 3');
    expect($names())->toBe(['Goal 3']);

    $page->assertSeeHtml('data-testid="archived-goals"')->assertSee('Retired');
});

it('shows the summary strip figures', function () {
    $goal = goalPagesGoal($this->user, ['target_amount' => 100000, 'target_date' => '2026-07-20'], balance: 40000);
    $account = GoalAccount::query()->where('goal_id', $goal->id)->firstOrFail()->account;
    Transaction::factory()->for($this->user)->for($account)->credit()->create(['amount' => 60000, 'post_date' => '2026-03-10']);
    Transaction::factory()->for($this->user)->for($account)->credit()->create(['amount' => 12000, 'post_date' => '2026-02-10']);

    $summary = Livewire::actingAs($this->user)->test(GoalList::class)->instance()->overview['summary'];

    expect($summary['inflow'])->toBe(60000)
        ->and($summary['priorInflow'])->toBe(12000)
        ->and($summary['onTrack'])->toBe(1)
        ->and($summary['behind'])->toBe(0)
        ->and($summary['neededPerMonth'])->toBeGreaterThan(0);
});

it('creates and edits a goal through the form', function () {
    $account = Account::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)->test(GoalList::class)
        ->call('startCreate')
        ->set('name', 'Holiday')
        ->set('targetAmount', '1500.00')
        ->set('targetDate', '2026-12-01')
        ->set('accountIds', [(string) $account->id])
        ->set('earmarks', [(string) $account->id => '200'])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    $goal = Goal::query()->where('user_id', $this->user->id)->sole();

    expect($goal->name)->toBe('Holiday')
        ->and($goal->target_amount)->toBe(150000)
        ->and(GoalAccount::query()->where('goal_id', $goal->id)->sole()->earmark_amount)->toBe(20000);

    Livewire::actingAs($this->user)->test(GoalList::class)
        ->call('edit', $goal->id)
        ->assertSet('name', 'Holiday')
        ->set('name', 'Big holiday')
        ->call('save')
        ->assertHasNoErrors();

    expect($goal->fresh()->name)->toBe('Big holiday');
});

it('refuses a goal without an account and edits only the own goals', function () {
    Livewire::actingAs($this->user)->test(GoalList::class)
        ->call('startCreate')
        ->set('name', 'Holiday')
        ->set('targetAmount', '100')
        ->call('save')
        ->assertHasErrors('goal');

    $foreign = goalPagesGoal(User::factory()->create());

    expect(fn () => Livewire::actingAs($this->user)->test(GoalList::class)->call('edit', $foreign->id))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(Goal::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('audits archiving a goal', function () {
    $goal = goalPagesGoal($this->user);

    Livewire::actingAs($this->user)->test(GoalDetail::class, ['goalId' => $goal->id])->call('archive')->assertHasNoErrors();

    $event = AuditEvent::query()->where('action', 'livewire.goal-detail.archive')->sole();

    expect($event->user_id)->toBe($this->user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success)
        ->and($goal->fresh()->status)->toBe(GoalStatus::Archived);
});

it('returns not found for another user goal', function () {
    $foreign = goalPagesGoal(User::factory()->create());

    $this->actingAs($this->user)->get(route('goals.show', $foreign))->assertNotFound();
});

it('runs the lifecycle actions on the detail page', function () {
    $goal = goalPagesGoal($this->user, ['target_amount' => 1000], balance: 5000);

    $page = Livewire::actingAs($this->user)->test(GoalDetail::class, ['goalId' => $goal->id]);

    $page->call('pause');
    expect($goal->fresh()->status)->toBe(GoalStatus::Paused);

    $page->call('resume');
    expect($goal->fresh()->status)->toBe(GoalStatus::Active);

    $page->call('complete');
    expect($goal->fresh()->status)->toBe(GoalStatus::Completed);

    $page->call('reopen')->call('archive')->call('unarchive');
    expect($goal->fresh()->status)->toBe(GoalStatus::Active);
});

it('shows a refusal when a reserve is completed', function () {
    $goal = goalPagesGoal($this->user, ['kind' => 'reserve', 'target_date' => null], balance: 5000);

    Livewire::actingAs($this->user)->test(GoalDetail::class, ['goalId' => $goal->id])
        ->call('complete')
        ->assertHasErrors('goal')
        ->assertSee('A maintained reserve cannot be completed.');
});

it('records a spend, pledges, renews and cancels on the detail page', function () {
    $goal = goalPagesGoal($this->user, ['target_amount' => 100000], balance: 50000);
    $account = GoalAccount::query()->where('goal_id', $goal->id)->firstOrFail()->account;

    $page = Livewire::actingAs($this->user)->test(GoalDetail::class, ['goalId' => $goal->id])
        ->set('spendAmount', '100')
        ->call('recordSpend')
        ->assertHasNoErrors();

    expect((int) GoalSpend::query()->where('goal_id', $goal->id)->sum('amount'))->toBe(10000);

    $page->set('pledgeAccountId', (string) $account->id)->set('pledgeAmount', '25')->call('pledge')->assertHasNoErrors();

    $pledge = GoalPledge::query()->where('goal_id', $goal->id)->sole();
    $expires = $pledge->expires_at;

    $page->assertSeeHtml('data-testid="pledge-row"')->call('renewPledge', $pledge->id);

    expect($pledge->fresh()->expires_at->greaterThan($expires))->toBeTrue();

    $page->call('cancelPledge', $pledge->id);

    expect($pledge->fresh()->status->value)->toBe('cancelled');
});

it('keeps pledge actions to the goal on the page', function () {
    $goal = goalPagesGoal($this->user, [], balance: 1000);
    $foreign = goalPagesGoal(User::factory()->create(), [], balance: 1000);
    $foreignPledge = GoalPledge::factory()->create(['goal_id' => $foreign->id, 'account_id' => GoalAccount::query()->where('goal_id', $foreign->id)->firstOrFail()->account_id]);

    expect(fn () => Livewire::actingAs($this->user)->test(GoalDetail::class, ['goalId' => $goal->id])->call('cancelPledge', $foreignPledge->id))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect($foreignPledge->fresh()->status->value)->toBe('open');
});

it('shows progress, projection, funding and the callout on the detail page', function () {
    $goal = goalPagesGoal($this->user, ['name' => 'Car', 'target_amount' => 500000, 'target_date' => '2026-04-01'], balance: 10000);

    Livewire::actingAs($this->user)->test(GoalDetail::class, ['goalId' => $goal->id])
        ->assertSee('Car')
        ->assertSeeHtml('data-testid="goal-progress"')
        ->assertSeeHtml('data-testid="goal-projection"')
        ->assertSeeHtml('data-testid="projected-shortfall"')
        ->assertSeeHtml('data-testid="goal-funding"')
        ->assertSee('to catch up')
        ->assertSee('10 days left');
});

it('shows cash on hand net of goal earmarks outside allocations', function () {
    $plan = BudgetPlan::factory()->for($this->user)->create(['period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'total_amount' => 100000, 'initialised_at' => '2026-03-01']);
    Account::factory()->for($this->user)->create(['balance' => 300000]);
    goalPagesGoal($this->user, [], balance: 200000, earmark: 120000);

    $page = Livewire::actingAs($this->user)->test(MonthlyBudget::class);
    $before = $page->instance()->summary;

    $page->assertSeeHtml('data-testid="cash-on-hand"')->assertSee('$3,800.00');

    expect($page->instance()->cashOnHand)->toBe(380000)
        ->and($before->allocated)->toBe(0)
        ->and($before->availableToAllocate)->toBe($plan->total_amount);

    Goal::query()->delete();

    $after = Livewire::actingAs($this->user)->test(MonthlyBudget::class)->instance();

    expect($after->summary->allocated)->toBe($before->allocated)
        ->and($after->summary->availableToAllocate)->toBe($before->availableToAllocate)
        ->and($after->cashOnHand)->toBe(500000);
});

it('hides cash on hand while goals are off', function () {
    config(['budget.goals_enabled' => false]);
    BudgetPlan::factory()->for($this->user)->create(['period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'initialised_at' => '2026-03-01']);

    Livewire::actingAs($this->user)->test(MonthlyBudget::class)->assertDontSeeHtml('data-testid="cash-on-hand"');
});

it('counts a goal that claims a whole account as earmarking its balance and ignores other users and investments', function () {
    Account::factory()->for($this->user)->create(['balance' => 100000]);
    Account::factory()->for($this->user)->investment()->create(['balance' => 900000]);
    goalPagesGoal($this->user, [], balance: 50000, earmark: null);
    goalPagesGoal(User::factory()->create(), [], balance: 70000, earmark: null);

    expect(app(App\Services\CashOnHandCalculator::class)->for($this->user))->toBe(100000);
});

it('converts cash balances into the base currency when multi-currency is on', function () {
    config(['budget.multi_currency' => true, 'services.exchange_rates.enabled' => false]);
    $user = User::factory()->create(['base_currency' => 'AUD']);
    Account::factory()->for($user)->create(['balance' => 100000, 'currency' => 'AUD']);
    Account::factory()->for($user)->create(['balance' => 100000, 'currency' => 'USD']);
    App\Models\ExchangeRate::factory()->create(['from_currency' => 'USD', 'to_currency' => 'AUD', 'rate_date' => '2026-03-20', 'rate' => 1.5]);

    expect(app(App\Services\CashOnHandCalculator::class)->for($user))->toBe(250000);
});

it('compares two adjacent thirty day savings windows', function () {
    $goal = goalPagesGoal($this->user, [], balance: 1000);
    $account = GoalAccount::query()->where('goal_id', $goal->id)->firstOrFail()->account;

    foreach (['2026-03-22', '2026-02-21'] as $date) {
        Transaction::factory()->for($this->user)->for($account)->credit()->create(['amount' => 10000, 'post_date' => $date]);
    }

    foreach (['2026-02-20', '2026-01-22'] as $date) {
        Transaction::factory()->for($this->user)->for($account)->credit()->create(['amount' => 5000, 'post_date' => $date]);
    }

    Transaction::factory()->for($this->user)->for($account)->credit()->create(['amount' => 99999, 'post_date' => '2026-01-21']);

    $summary = Livewire::actingAs($this->user)->test(GoalList::class)->instance()->overview['summary'];

    expect($summary['inflow'])->toBe(20000)
        ->and($summary['priorInflow'])->toBe(10000);
});

it('carries a linked account balance across days its history does not cover', function () {
    $goal = Goal::factory()->for($this->user)->create(['target_amount' => 500000]);
    $first = Account::factory()->for($this->user)->create(['balance' => 100000]);
    $second = Account::factory()->for($this->user)->create(['balance' => 200000]);

    foreach ([$first, $second] as $account) {
        GoalAccount::query()->create(['goal_id' => $goal->id, 'account_id' => $account->id]);
    }

    foreach (['2026-03-18', '2026-03-22'] as $date) {
        App\Models\AccountDailyBalance::query()->create(['account_id' => $first->id, 'balance_date' => $date, 'start_balance' => 100000, 'end_balance' => 100000, 'currency' => 'AUD']);
    }

    App\Models\AccountDailyBalance::query()->create(['account_id' => $second->id, 'balance_date' => '2026-03-18', 'start_balance' => 200000, 'end_balance' => 200000, 'currency' => 'AUD']);

    $series = collect(Livewire::actingAs($this->user)->test(GoalDetail::class, ['goalId' => $goal->id])->instance()->projection->saved);

    expect($series->last()['date'])->toBe('2026-03-22')
        ->and($series->last()['amount'])->toBe(300000)
        ->and($series->pluck('amount')->unique()->all())->toBe([300000]);
});

it('blocks calls on an open goals page once the flag is turned off', function () {
    $goal = goalPagesGoal($this->user);
    $list = Livewire::actingAs($this->user)->test(GoalList::class);
    $detail = Livewire::actingAs($this->user)->test(GoalDetail::class, ['goalId' => $goal->id]);

    config(['budget.goals_enabled' => false]);

    $list->call('startCreate')->assertNotFound();
    $detail->call('archive')->assertNotFound();

    expect($goal->fresh()->status)->toBe(GoalStatus::Active);
});

it('refuses funding accounts in different currencies', function () {
    $aud = Account::factory()->for($this->user)->create(['currency' => 'AUD']);
    $usd = Account::factory()->for($this->user)->create(['currency' => 'USD']);

    Livewire::actingAs($this->user)->test(GoalList::class)
        ->call('startCreate')
        ->set('name', 'Trip')
        ->set('targetAmount', '100')
        ->set('accountIds', [(string) $aud->id, (string) $usd->id])
        ->call('save')
        ->assertHasErrors('goal')
        ->assertSee('Choose accounts in one currency to fund this goal.');

    expect(Goal::query()->where('user_id', $this->user->id)->exists())->toBeFalse();
});

it('saves a reserve with a fixed floor and without months of expenses', function () {
    $account = Account::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)->test(GoalList::class)
        ->call('startCreate')
        ->set('name', 'Buffer')
        ->set('kind', 'reserve')
        ->set('targetAmount', '2500')
        ->set('accountIds', [(string) $account->id])
        ->call('save')
        ->assertHasNoErrors();

    expect(Goal::query()->where('user_id', $this->user->id)->sole()->target_amount)->toBe(250000);
});

it('converts the summary strip into the base currency', function () {
    config(['budget.multi_currency' => true, 'services.exchange_rates.enabled' => false]);
    $user = User::factory()->create(['base_currency' => 'AUD']);
    App\Models\ExchangeRate::factory()->create(['from_currency' => 'USD', 'to_currency' => 'AUD', 'rate_date' => '2026-03-10', 'rate' => 1.5]);

    foreach (['AUD', 'USD'] as $currency) {
        $goal = Goal::factory()->for($user)->create(['target_amount' => 1000000, 'target_date' => '2026-09-22']);
        $account = Account::factory()->for($user)->create(['currency' => $currency, 'balance' => 0]);
        GoalAccount::query()->create(['goal_id' => $goal->id, 'account_id' => $account->id]);
        Transaction::factory()->for($user)->for($account)->credit()->create(['amount' => 10000, 'post_date' => '2026-03-10', 'currency' => $currency]);
    }

    $summary = Livewire::actingAs($user)->test(GoalList::class)->instance()->overview['summary'];

    expect($summary['inflow'])->toBe(25000);
});

it('rejects a spend account that no longer funds the goal', function () {
    $goal = goalPagesGoal($this->user, ['target_amount' => 100000], balance: 50000);
    $other = Account::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)->test(GoalDetail::class, ['goalId' => $goal->id])
        ->set('spendAmount', '10')
        ->set('spendAccountId', (string) $other->id)
        ->call('recordSpend')
        ->assertHasErrors('goal');

    expect(GoalSpend::query()->where('goal_id', $goal->id)->exists())->toBeFalse();
});

it('measures how long ago a pledge matched in the user timezone', function () {
    $this->travelTo('2026-03-22 20:30:00');
    $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);
    $goal = goalPagesGoal($user, [], balance: 1000);
    $pledge = GoalPledge::factory()->matched()->create(['goal_id' => $goal->id, 'account_id' => GoalAccount::query()->where('goal_id', $goal->id)->firstOrFail()->account_id]);
    $pledge->forceFill(['updated_at' => '2026-03-22 20:10:00'])->saveQuietly();

    expect(Livewire::actingAs($user)->test(GoalDetail::class, ['goalId' => $goal->id])->instance()->lastMatchedDays)->toBe(0);
});

it('keeps an explicit deadline when projecting and shows the shortfall once it is due', function () {
    $goal = goalPagesGoal($this->user, ['target_amount' => 100000, 'target_date' => '2026-03-22'], balance: 40000);
    $account = GoalAccount::query()->where('goal_id', $goal->id)->firstOrFail()->account;
    Transaction::factory()->for($this->user)->for($account)->credit()->create(['amount' => 60000, 'post_date' => '2026-02-10']);

    $projection = Livewire::actingAs($this->user)->test(GoalDetail::class, ['goalId' => $goal->id])->instance()->projection;

    expect($projection->projectedOn->toDateString())->toBe('2026-03-22')
        ->and($projection->projectedEnd)->toBe(40000)
        ->and($projection->shortfall)->toBe(60000);
});

it('does not promise an on-track finish for a paused goal', function () {
    $goal = goalPagesGoal($this->user, ['status' => GoalStatus::Paused, 'target_amount' => 500000, 'target_date' => '2026-09-01'], balance: 1000);

    Livewire::actingAs($this->user)->test(GoalDetail::class, ['goalId' => $goal->id])
        ->assertSeeHtml('data-testid="paused-callout"')
        ->assertDontSee('you reach the goal by');
});

it('ends the projection series at the saved amount for an over-earmarked shared account', function () {
    $account = Account::factory()->for($this->user)->create(['balance' => 100000]);
    $goal = Goal::factory()->for($this->user)->create(['target_amount' => 500000, 'target_date' => '2026-09-22']);
    $other = Goal::factory()->for($this->user)->create(['target_amount' => 500000]);

    foreach ([$goal, $other] as $each) {
        GoalAccount::query()->create(['goal_id' => $each->id, 'account_id' => $account->id, 'earmark_amount' => 80000]);
    }

    foreach (['2026-03-10' => 60000, '2026-03-22' => 100000] as $date => $balance) {
        App\Models\AccountDailyBalance::query()->create(['account_id' => $account->id, 'balance_date' => $date, 'start_balance' => $balance, 'end_balance' => $balance, 'currency' => 'AUD']);
    }

    $page = Livewire::actingAs($this->user)->test(GoalDetail::class, ['goalId' => $goal->id]);
    $series = collect($page->instance()->projection->saved);
    $saved = app(App\Services\GoalProgressCalculator::class)->savedAmount($goal);

    expect($saved)->toBe(50000)
        ->and($series->last()['amount'])->toBe($saved)
        ->and($page->instance()->progress->savedAmount)->toBe($saved)
        ->and($series->firstWhere('date', '2026-03-10')['amount'])->toBe(30000);
});

it('keeps a completed goal flat at its frozen amount and adds spends to the series', function () {
    $account = Account::factory()->for($this->user)->create(['balance' => 100000]);
    $goal = Goal::factory()->for($this->user)->create(['target_amount' => 500000, 'frozen_saved_amount' => 70000, 'status' => GoalStatus::Completed]);
    GoalAccount::query()->create(['goal_id' => $goal->id, 'account_id' => $account->id]);
    App\Models\AccountDailyBalance::query()->create(['account_id' => $account->id, 'balance_date' => '2026-03-20', 'start_balance' => 100000, 'end_balance' => 100000, 'currency' => 'AUD']);

    $series = collect(Livewire::actingAs($this->user)->test(GoalDetail::class, ['goalId' => $goal->id])->instance()->projection->saved);

    expect($series->pluck('amount')->unique()->all())->toBe([70000]);
});
