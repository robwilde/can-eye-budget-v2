<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountClass;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Livewire\Dashboard;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPlan;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\RedbarkFeed;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

// ── Positive / negative / empty buffer ─────────────────────────────

test('renders positive buffer with YOU CAN AFFORD headline', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(7),
    ]);
    Account::factory()->for($user)->create(['balance' => 500000]);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSee('YOU CAN AFFORD')
        ->assertDontSee('YOU ARE SHORT BY');
});

test('renders negative buffer with YOU ARE SHORT BY headline', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(10),
    ]);
    $account = Account::factory()->for($user)->create(['balance' => 5000]);

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 50000,
        'is_active' => true,
        'start_date' => now()->addDay(),
        'frequency' => RecurrenceFrequency::DontRepeat,
    ]);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSee('YOU ARE SHORT BY')
        ->assertDontSee('YOU CAN AFFORD');
});

test('headline buffer equals available minus needed', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(7),
    ]);
    $account = Account::factory()->for($user)->create(['balance' => 242000]);

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 111390,
        'is_active' => true,
        'start_date' => now()->addDay(),
        'frequency' => RecurrenceFrequency::DontRepeat,
    ]);

    $component = Livewire::actingAs($user)->test(Dashboard::class)->instance();

    expect($component->buffer())
        ->toBe($component->totalAvailable() - $component->totalNeeded());
});

test('renders empty state when pay cycle is not configured', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSuccessful()
        ->assertSee('SET UP PAY CYCLE');
});

// ── Money Triad parity ────────────────────────────────────────────

test('triad numbers match User helper methods', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(14),
    ]);
    Account::factory()->for($user)->create(['balance' => 250000]);
    Account::factory()->creditCard()->for($user)->create([
        'balance' => -30000,
        'credit_limit' => 500000,
    ]);

    $numbers = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->numbers();

    expect($numbers['owed'])->toBe($user->totalOwed())
        ->and($numbers['available'])->toBe($user->totalAvailable())
        ->and($numbers['needed'])->toBe($user->totalNeededUntilPayday());
});

test('triad renders Owed Available and Needed labels', function () {
    $user = User::factory()->withPayCycle()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSee('Owed')
        ->assertSee('Available')
        ->assertSee('Needed');
});

test('triad matches User helper methods on the same seed', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(7),
    ]);
    Account::factory()->for($user)->create(['balance' => 200000]);
    Account::factory()->creditCard()->for($user)->create([
        'balance' => -40000,
        'credit_limit' => 500000,
    ]);

    $dashboard = Livewire::actingAs($user)->test(Dashboard::class);

    $dashboardOwed = $dashboard->instance()->totalOwed();
    $dashboardAvailable = $dashboard->instance()->totalAvailable();

    expect($dashboardOwed)->toBe($user->totalOwed())
        ->and($dashboardAvailable)->toBe($user->totalAvailable());
});

// ── Sidebar widgets ────────────────────────────────────────────────

test('renders pay cycle calendar widget in place of recent activity', function () {
    $user = User::factory()->withPayCycle()->create();
    Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSeeLivewire('dashboard.pay-cycle-calendar')
        ->assertDontSee('Recent activity');
});

test('budgets this cycle section renders when budgets exist', function () {
    $user = User::factory()->withPayCycle()->create();
    Account::factory()->for($user)->create();
    $category = Category::factory()->for($user)->create();
    Budget::factory()->for($user)->create([
        'category_id' => $category->id,
        'name' => 'Groceries',
        'limit_amount' => 80000,
    ]);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSee('Budgets this cycle')
        ->assertSee('Groceries');
});

test('budgets this cycle reports zero spent for an uncategorised budget instead of summing every debit', function () {
    $user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(7),
    ]);
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->for($user)->create();

    Transaction::factory()->debit()->for($user)->for($account)->create([
        'amount' => 447468,
        'post_date' => now(),
        'category_id' => $category->id,
    ]);

    Budget::factory()->for($user)->create([
        'category_id' => $category->id,
        'name' => 'Groceries',
        'limit_amount' => 80000,
    ]);
    Budget::factory()->for($user)->create([
        'category_id' => null,
        'name' => 'Insurance',
        'limit_amount' => 30000,
    ]);

    $rows = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->budgetsThisCycle()
        ->keyBy(fn (array $row): string => $row['budget']->name);

    expect($rows['Groceries']['spent'])->toBe(447468)
        ->and($rows['Insurance']['spent'])->toBe(0);
});

test('budgets this cycle ignores lines that belong to a budget plan', function () {
    $user = User::factory()->withPayCycle()->create();
    Budget::factory()->for($user)->create(['name' => 'Insurance', 'limit_amount' => 30000]);
    $plan = BudgetPlan::factory()->for($user)->create();
    Budget::factory()->inPlan($plan)->create(['name' => 'Groceries', 'limit_amount' => null]);

    $names = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->budgetsThisCycle()
        ->map(fn (array $row): string => $row['budget']->name);

    expect($names->all())->toBe(['Insurance']);
});

it('lists current budget plan lines on the dashboard', function () {
    $this->travelTo('2026-03-22 12:00:00');
    $user = User::factory()->withPayCycle()->create();
    $account = Account::factory()->for($user)->create();
    Budget::factory()->for($user)->create(['name' => 'Legacy insurance', 'limit_amount' => 30000]);
    $plan = BudgetPlan::factory()->for($user)->create([
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'total_amount' => 100000,
        'initialised_at' => '2026-03-01',
    ]);
    $groceries = Budget::factory()->inPlan($plan)->withCategory(Category::factory()->for($user)->create(['name' => 'Groceries']))->create(['limit_amount' => 40000]);
    Budget::factory()->inPlan($plan)->withCategory(Category::factory()->for($user)->create(['name' => 'Idle']))->create(['limit_amount' => 0]);
    Budget::factory()->inPlan($plan)->withCategory(Category::factory()->for($user)->create(['name' => 'Shared one']))->create(['limit_amount' => null]);
    Transaction::factory()->debit()->for($user)->for($account)->create([
        'amount' => 12500,
        'post_date' => '2026-03-10',
        'category_id' => $groceries->category_id,
    ]);

    $rows = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->budgetsThisCycle()
        ->keyBy(fn (array $row): string => $row['budget']->name);

    expect($rows->keys()->all())->toBe(['Groceries'])
        ->and($rows['Groceries']['spent'])->toBe(12500)
        ->and($rows['Groceries']['limit'])->toBe(40000);
});

it('does not bring back plan-less budgets when the initialised month has no figures', function () {
    $this->travelTo('2026-03-22 12:00:00');
    $user = User::factory()->withPayCycle()->create();
    Budget::factory()->for($user)->create(['name' => 'Legacy insurance', 'limit_amount' => 30000]);
    $plan = BudgetPlan::factory()->for($user)->create(['period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'initialised_at' => '2026-03-01']);
    Budget::factory()->inPlan($plan)->create(['name' => 'Idle', 'limit_amount' => 0]);

    $rows = Livewire::actingAs($user)->test(Dashboard::class)->instance()->budgetsThisCycle();

    expect($rows)->toBeEmpty();
});

it('shows the current category name on dashboard plan rows', function () {
    $this->travelTo('2026-03-22 12:00:00');
    $user = User::factory()->withPayCycle()->create();
    $plan = BudgetPlan::factory()->for($user)->create(['period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'initialised_at' => '2026-03-01']);
    $line = Budget::factory()->inPlan($plan)->create(['name' => 'Groceries', 'limit_amount' => 40000]);
    $line->category->update(['name' => 'Supermarket']);

    $names = Livewire::actingAs($user)->test(Dashboard::class)->instance()->budgetsThisCycle()
        ->map(fn (array $row): string => $row['budget']->name);

    expect($names->all())->toBe(['Supermarket']);
});

it('keeps listing plan-less budgets while the current month is not set up', function () {
    $this->travelTo('2026-03-22 12:00:00');
    $user = User::factory()->withPayCycle()->create();
    Budget::factory()->for($user)->create(['name' => 'Legacy insurance', 'limit_amount' => 30000]);
    $plan = BudgetPlan::factory()->for($user)->create(['period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'initialised_at' => null]);
    Budget::factory()->inPlan($plan)->create(['name' => 'Groceries', 'limit_amount' => 40000]);

    $names = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->budgetsThisCycle()
        ->map(fn (array $row): string => $row['budget']->name);

    expect($names->all())->toBe(['Legacy insurance']);
});

test('next three planned section renders upcoming plans', function () {
    $user = User::factory()->withPayCycle()->create();
    $account = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'description' => 'Rent Payment',
        'direction' => TransactionDirection::Debit,
        'start_date' => now()->addDays(3),
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSee('Next 3 planned')
        ->assertSee('Rent Payment');
});

test('spend last 7 days section renders sum and sparkline', function () {
    $user = User::factory()->withPayCycle()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->debit()->for($user)->for($account)->create([
        'amount' => 12345,
        'post_date' => now()->subDays(1),
    ]);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSee('Spend last 7 days')
        ->assertSeeHtml('class="spark"');
});

// ── Money Triad totals ─────────────────────────────────────────────

test('owed total sums credit card and loan debts', function () {
    $user = User::factory()->withPayCycle()->create();
    Account::factory()->creditCard()->for($user)->create([
        'balance' => -50000,
        'credit_limit' => 500000,
    ]);
    Account::factory()->loan()->for($user)->create(['balance' => -300000]);

    $numbers = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->numbers();

    expect($numbers['owed'])->toBe(350000);
});

test('owed total is zero when no debt accounts exist', function () {
    $user = User::factory()->withPayCycle()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);

    $numbers = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->numbers();

    expect($numbers['owed'])->toBe(0);
});

test('available total excludes loans and mortgages', function () {
    $user = User::factory()->withPayCycle()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);
    Account::factory()->loan()->for($user)->create(['balance' => -500000]);
    Account::factory()->mortgage()->for($user)->create(['balance' => -50000000]);

    $numbers = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->numbers();

    expect($numbers['available'])->toBe(100000);
});

test('available total includes credit card available credit', function () {
    $user = User::factory()->withPayCycle()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);
    Account::factory()->creditCard()->for($user)->create([
        'balance' => -50000,
        'credit_limit' => 500000,
    ]);

    $numbers = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->numbers();

    expect($numbers['available'])->toBe(550000);
});

test('closed accounts are excluded from totals', function () {
    $user = User::factory()->withPayCycle()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);
    Account::factory()->closed()->for($user)->create(['balance' => 999999]);

    $numbers = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->numbers();

    expect($numbers['available'])->toBe(100000);
});

test('inactive accounts are excluded from totals', function () {
    $user = User::factory()->withPayCycle()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);
    Account::factory()->inactive()->for($user)->create(['balance' => 999999]);

    $numbers = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->numbers();

    expect($numbers['available'])->toBe(100000);
});

test('totals isolate current user from other users accounts', function () {
    $user = User::factory()->withPayCycle()->create();
    $otherUser = User::factory()->create();

    Account::factory()->for($user)->create(['balance' => 100000]);
    Account::factory()->for($otherUser)->create(['balance' => 500000]);
    Account::factory()->creditCard()->for($otherUser)->create([
        'balance' => -75000,
        'credit_limit' => 500000,
    ]);

    $numbers = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->numbers();

    expect($numbers['available'])->toBe(100000)
        ->and($numbers['owed'])->toBe(0);
});

test('available reflects credit limit for a limit-bearing non-credit-card account', function () {
    $user = User::factory()->withPayCycle()->create();
    Account::factory()->for($user)->create(['type' => AccountClass::Transaction, 'balance' => 219340]);
    Account::factory()->for($user)->create(['type' => AccountClass::Transaction, 'balance' => -340642, 'credit_limit' => 500000]);

    $numbers = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->numbers();

    expect($numbers['available'])->toBe(378698)
        ->and($numbers['owed'])->toBe(340642);
});
// ── Spend last 7 days ──────────────────────────────────────────────

test('spend last 7 days sums debit transactions within the window', function () {
    $user = User::factory()->withPayCycle()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->debit()->for($user)->for($account)->create([
        'amount' => 5000,
        'post_date' => now()->subDays(1),
    ]);

    Transaction::factory()->debit()->for($user)->for($account)->create([
        'amount' => 3000,
        'post_date' => now()->subDays(3),
    ]);

    $spend = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->spendLast7Days();

    expect($spend['sum'])->toBe(8000);
});

test('spend last 7 days zero-fills days with no transactions', function () {
    $user = User::factory()->withPayCycle()->create();
    Account::factory()->for($user)->create();

    $spend = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->spendLast7Days();

    expect($spend['sum'])->toBe(0)
        ->and($spend['sparkline'])->toHaveCount(14)
        ->and(array_sum($spend['sparkline']))->toBe(0);
});

test('spend last 7 days isolates current user from other users transactions', function () {
    $user = User::factory()->withPayCycle()->create();
    $otherUser = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $otherAccount = Account::factory()->for($otherUser)->create();

    Transaction::factory()->debit()->for($user)->for($account)->create([
        'amount' => 3000,
        'post_date' => now()->subDays(2),
    ]);

    Transaction::factory()->debit()->for($otherUser)->for($otherAccount)->create([
        'amount' => 99999,
        'post_date' => now()->subDays(2),
    ]);

    $spend = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->spendLast7Days();

    expect($spend['sum'])->toBe(3000);
});

test('spend last 7 days ignores credits and only sums debits', function () {
    $user = User::factory()->withPayCycle()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->debit()->for($user)->for($account)->create([
        'amount' => 4000,
        'post_date' => now()->subDays(2),
    ]);

    Transaction::factory()->credit()->for($user)->for($account)->create([
        'amount' => 90000,
        'post_date' => now()->subDays(2),
    ]);

    $spend = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->spendLast7Days();

    expect($spend['sum'])->toBe(4000);
});

test('spend last 7 days excludes internal transfers', function () {
    $user = User::factory()->withPayCycle()->create();
    $accountA = Account::factory()->for($user)->create();
    $accountB = Account::factory()->for($user)->create();

    $debit = Transaction::factory()->debit()->for($user)->for($accountA)->create([
        'amount' => 250000,
        'post_date' => now()->subDay(),
    ]);
    $credit = Transaction::factory()->credit()->for($user)->for($accountB)->create([
        'amount' => 250000,
        'post_date' => now()->subDay(),
        'transfer_pair_id' => $debit->id,
    ]);
    $debit->update(['transfer_pair_id' => $credit->id]);

    Transaction::factory()->debit()->for($user)->for($accountA)->create([
        'amount' => 5000,
        'post_date' => now()->subDay(),
    ]);

    $spend = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->spendLast7Days();

    expect($spend['sum'])->toBe(5000);
});

test('spend last 7 days counts a transfer to an untracked account but not one from it', function () {
    $user = User::factory()->withPayCycle()->create();
    $tracked = Account::factory()->for($user)->create();
    $hidden = Account::factory()->for($user)->untracked()->create();

    $out = Transaction::factory()->debit()->for($user)->for($tracked)->create(['amount' => 7000, 'post_date' => now()->subDay()]);
    $outMirror = Transaction::factory()->credit()->for($user)->for($hidden)->create(['amount' => 7000, 'post_date' => now()->subDay(), 'transfer_pair_id' => $out->id]);
    $out->update(['transfer_pair_id' => $outMirror->id]);

    $in = Transaction::factory()->credit()->for($user)->for($tracked)->create(['amount' => 3000, 'post_date' => now()->subDay()]);
    $inMirror = Transaction::factory()->debit()->for($user)->for($hidden)->create(['amount' => 3000, 'post_date' => now()->subDay(), 'transfer_pair_id' => $in->id]);
    $in->update(['transfer_pair_id' => $inMirror->id]);

    $spend = Livewire::actingAs($user)->test(Dashboard::class)->instance()->spendLast7Days();

    expect($spend['sum'])->toBe(7000);
});

test('needed until payday counts a planned transfer to an untracked account but not one into a tracked account', function () {
    $this->travelTo(now()->startOfDay());
    $user = User::factory()->withPayCycle()->create(['next_pay_date' => now()->addDays(10)->toDateString()]);
    $tracked = Account::factory()->for($user)->create();
    $hidden = Account::factory()->for($user)->untracked()->create();

    $plan = fn (Account $from, Account $to, int $amount) => PlannedTransaction::factory()->for($user)->create([
        'account_id' => $from->id,
        'transfer_to_account_id' => $to->id,
        'direction' => TransactionDirection::Debit,
        'amount' => $amount,
        'start_date' => now()->addDays(2)->toDateString(),
        'frequency' => RecurrenceFrequency::DontRepeat,
        'is_active' => true,
    ]);

    $plan($tracked, $hidden, 9000);
    $plan($hidden, $tracked, 4000);

    expect($user->fresh()->totalNeededUntilPayday())->toBe(9000);
});

test('spend last 7 days excludes transactions categorised at the parent Transfer level', function () {
    $user = User::factory()->withPayCycle()->create();
    $account = Account::factory()->for($user)->create();
    $transferParent = Category::factory()->for($user)->create(['name' => 'Transfer']);

    Transaction::factory()->debit()->for($user)->for($account)->create([
        'amount' => 250000,
        'post_date' => now()->subDay(),
        'transfer_pair_id' => null,
        'category_id' => $transferParent->id,
    ]);

    Transaction::factory()->debit()->for($user)->for($account)->create([
        'amount' => 5000,
        'post_date' => now()->subDay(),
    ]);

    $spend = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->spendLast7Days();

    expect($spend['sum'])->toBe(5000);
});

// ── Layout ─────────────────────────────────────────────────────────

test('uses two-column layout class on lg breakpoint', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSeeHtml('lg:grid-cols-[1fr_300px]');
});

// ── Live refresh ───────────────────────────────────────────────────

test('refreshes pay-cycle figures when a transaction is saved elsewhere on the page', function () {
    $user = User::factory()->withPayCycle()->create(['next_pay_date' => now()->addDays(7)]);
    $account = Account::factory()->for($user)->create(['balance' => 242000]);

    $component = Livewire::actingAs($user)->test(Dashboard::class)
        ->assertDontSee('Spotify Premium');

    expect($component->get('totalNeeded'))->toBe(0);

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'description' => 'Spotify Premium',
        'direction' => TransactionDirection::Debit,
        'amount' => 50000,
        'is_active' => true,
        'start_date' => now()->addDay(),
        'frequency' => RecurrenceFrequency::DontRepeat,
    ]);

    $component->dispatch('transaction-saved')
        ->assertSee('Spotify Premium');

    expect($component->get('totalNeeded'))->toBe(50000);
});

// ── Connect-your-bank card ─────────────────────────────────────────

test('shows the connect your bank card when the user has no Redbark feed', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(Dashboard::class)
        ->assertSeeHtml('data-test="dashboard-connect-bank-card"')
        ->assertSee(route('connect-bank'));
});

test('hides the connect your bank card once the user has a Redbark feed', function () {
    $user = User::factory()->create();
    RedbarkFeed::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertDontSeeHtml('data-test="dashboard-connect-bank-card"');
});

test('spend last 7 days leaves out accounts excluded from reports', function () {
    $user = User::factory()->withPayCycle()->create();
    $included = Account::factory()->for($user)->create();
    $excluded = Account::factory()->for($user)->excludedFromReports()->create();

    Transaction::factory()->debit()->for($user)->for($included)->create([
        'amount' => 5000,
        'post_date' => now()->subDays(1),
    ]);
    Transaction::factory()->debit()->for($user)->for($excluded)->create([
        'amount' => 9000,
        'post_date' => now()->subDays(1),
    ]);

    $spend = Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->instance()
        ->spendLast7Days();

    expect($spend['sum'])->toBe(5000);
});

test('spend last 7 days ends on the user local date', function () {
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-03-31 14:30:00', 'UTC'));
    $sydney = User::factory()->withPayCycle()->withTimezone('Australia/Sydney')->create();
    $unset = User::factory()->withPayCycle()->create();

    foreach ([$sydney, $unset] as $user) {
        Transaction::factory()->debit()->for($user)->for(Account::factory()->for($user)->create())->create([
            'amount' => 5000,
            'post_date' => '2026-04-01',
        ]);
    }

    $local = Livewire::actingAs($sydney)->test(Dashboard::class)->instance()->spendLast7Days();
    $utc = Livewire::actingAs($unset)->test(Dashboard::class)->instance()->spendLast7Days();

    expect($local['sum'])->toBe(5000)
        ->and($utc['sum'])->toBe(0);
});

test('upcoming plans still due on the user local date are kept', function () {
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-04-01 03:00:00', 'UTC'));
    $la = User::factory()->withTimezone('America/Los_Angeles')->create();
    $account = Account::factory()->for($la)->create();
    PlannedTransaction::factory()->for($la)->for($account)->create([
        'start_date' => '2026-03-31',
        'until_date' => '2026-03-31',
        'frequency' => RecurrenceFrequency::EveryMonth,
        'is_active' => true,
    ]);

    $upcoming = Livewire::actingAs($la)->test(Dashboard::class)->instance()->nextThreePlanned();

    expect($upcoming)->toHaveCount(1)
        ->and($upcoming->first()['next']->toDateString())->toBe('2026-03-31');
});
