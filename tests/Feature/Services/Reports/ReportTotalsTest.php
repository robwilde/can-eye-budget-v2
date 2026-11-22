<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\TransactionDirection;
use App\Enums\TransactionStatus;
use App\Livewire\AccountManager;
use App\Livewire\CalendarView;
use App\Livewire\CategoryReport;
use App\Livewire\Dashboard;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Budget;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MedianMonthlySpend;
use App\Services\Reports\ReportAggregator;
use App\Support\Calendar\CashFlow;
use App\Support\Calendar\CashFlowLoader;
use App\Support\Insights\CountableFlow;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-15'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function reportCashFlow(User $user): CashFlow
{
    return app(CashFlowLoader::class)->load($user, CarbonImmutable::parse('2026-07-01'));
}

function reportSummary(User $user): array
{
    return Livewire::actingAs($user)->test(CategoryReport::class)->instance()->summary();
}

it('leaves pending transactions out of every report total', function () {
    $groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries']);

    $pending = Transaction::factory()->for($this->user)->pending()->debit()->create([
        'account_id' => $this->account->id,
        'category_id' => $groceries->id,
        'amount' => 10000,
        'post_date' => '2026-07-10',
    ]);

    $flow = reportCashFlow($this->user);

    expect($flow->spending)->toBe(0)
        ->and($flow->outflows)->toBe([])
        ->and(reportSummary($this->user)['out'])->toBe(0);

    $pending->update(['status' => TransactionStatus::Posted]);

    $flow = reportCashFlow($this->user);

    expect($flow->spending)->toBe(10000)
        ->and($flow->outflows)->toHaveCount(1)
        ->and(reportSummary($this->user)['out'])->toBe(10000);
});

it('leaves pending split lines out of report totals', function () {
    $transaction = Transaction::factory()->for($this->user)->pending()->debit()->create([
        'account_id' => $this->account->id,
        'amount' => 10000,
        'post_date' => '2026-07-10',
    ]);
    $transaction->splits()->create(['category_id' => null, 'amount' => 6000, 'position' => 1]);
    $transaction->splits()->create(['category_id' => null, 'amount' => 4000, 'position' => 2]);

    expect(reportCashFlow($this->user)->spending)->toBe(0);

    $transaction->update(['status' => TransactionStatus::Posted]);

    expect(reportCashFlow($this->user)->spending)->toBe(10000);
});

it('leaves excluded accounts out of report totals', function () {
    $excluded = Account::factory()->for($this->user)->excludedFromReports()->create();

    Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $excluded->id,
        'amount' => 20000,
        'post_date' => '2026-07-10',
    ]);
    Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => 3000,
        'post_date' => '2026-07-11',
    ]);

    expect(reportCashFlow($this->user)->spending)->toBe(3000)
        ->and(reportSummary($this->user)['out'])->toBe(3000);
});

it('nets refunds against their category', function () {
    $groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries']);

    Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'category_id' => $groceries->id,
        'amount' => 6000,
        'post_date' => '2026-07-10',
    ]);
    Transaction::factory()->for($this->user)->credit()->create([
        'account_id' => $this->account->id,
        'category_id' => $groceries->id,
        'amount' => 1000,
        'post_date' => '2026-07-11',
    ]);

    $flow = reportCashFlow($this->user);

    expect($flow->spending)->toBe(5000)
        ->and($flow->income)->toBe(0)
        ->and($flow->outflows)->toHaveCount(1)
        ->and($flow->outflows[0]['amount'])->toBe(5000);
});

it('keeps the transfer counting rule in cash flow', function () {
    $untracked = Account::factory()->for($this->user)->untracked()->create();
    $otherTracked = Account::factory()->for($this->user)->create();

    $transfer = function (Account $from, Account $to, int $amount) {
        $debit = Transaction::factory()->for($this->user)->debit()->create([
            'account_id' => $from->id,
            'amount' => $amount,
            'post_date' => '2026-07-12',
        ]);
        $credit = Transaction::factory()->for($this->user)->credit()->create([
            'account_id' => $to->id,
            'amount' => $amount,
            'post_date' => '2026-07-12',
            'transfer_pair_id' => $debit->id,
        ]);
        $debit->update(['transfer_pair_id' => $credit->id]);
    };

    $transfer($this->account, $untracked, 30000);
    $transfer($this->account, $otherTracked, 50000);

    $flow = reportCashFlow($this->user);

    expect($flow->spending)->toBe(30000)
        ->and($flow->income)->toBe(0);
});

it('audits the exclude from reports toggle and refuses other users\' accounts', function () {
    Livewire::actingAs($this->user)
        ->test(AccountManager::class)
        ->call('openEditModal', $this->account->id)
        ->set('excludeFromReports', true)
        ->call('save')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->where('action', 'livewire.account-manager.save')->sole();

    expect($event->user_id)->toBe($this->user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success)
        ->and($this->account->fresh()->exclude_from_reports)->toBeTrue();

    $stranger = Account::factory()->for(User::factory()->create())->create();

    Livewire::actingAs($this->user)
        ->test(AccountManager::class)
        ->call('openEditModal', $stranger->id)
        ->assertSet('editingAccountId', null)
        ->set('excludeFromReports', true)
        ->call('save')
        ->assertHasErrors('name');

    expect($stranger->fresh()->exclude_from_reports)->toBeFalse()
        ->and(Account::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

it('bounds the report months by posted rows only', function () {
    foreach (['2026-03-10', '2026-09-10'] as $date) {
        Transaction::factory()->for($this->user)->pending()->debit()->create([
            'account_id' => $this->account->id,
            'amount' => 1000,
            'post_date' => $date,
        ]);
    }

    $bounds = app(ReportAggregator::class)->monthBounds($this->user, 'actual', null, null);

    expect($bounds['start']->format('Y-m'))->toBe('2026-07')
        ->and($bounds['end']->format('Y-m'))->toBe('2026-07');
});

it('keeps a plan matched only by a pending transaction in the calendar to come until it posts', function () {
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->monthly()->create([
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-07-10',
    ]);
    $transaction = Transaction::factory()->for($this->user)->pending()->debit()->create([
        'account_id' => $this->account->id,
        'amount' => -5000,
        'post_date' => '2026-07-10',
        'planned_transaction_id' => $plan->id,
    ]);

    $component = Livewire::actingAs($this->user)->test(CalendarView::class);

    expect($component->get('plannedToCome'))->toBe(['income' => 0, 'spend' => 5000])
        ->and($component->get('monthFlow')->actualExpense)->toBe(0)
        ->and($component->get('monthFlow')->projectedNet())->toBe(-5000);

    $transaction->update(['status' => TransactionStatus::Posted]);

    $component = Livewire::actingAs($this->user)->test(CalendarView::class);

    expect($component->get('plannedToCome'))->toBe(['income' => 0, 'spend' => 0])
        ->and($component->get('monthFlow')->actualExpense)->toBe(5000)
        ->and($component->get('monthFlow')->projectedNet())->toBe(-5000);
});

it('still counts pending spend in the dashboard budget card', function () {
    $user = User::factory()->withPayCycle()->create(['next_pay_date' => now()->addDays(7)]);
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->for($user)->create();
    Budget::factory()->for($user)->create(['category_id' => $category->id, 'name' => 'Groceries', 'limit_amount' => 80000]);

    Transaction::factory()->for($user)->for($account)->pending()->debit()->create([
        'amount' => 4000,
        'post_date' => now(),
        'category_id' => $category->id,
    ]);

    $row = Livewire::actingAs($user)->test(Dashboard::class)->instance()->budgetsThisCycle()->sole();

    expect($row['spent'])->toBe(4000);
});

function pendingMatchedPlan(object $test, Account $transactionAccount, array $transactionOverrides = []): Transaction
{
    $plan = PlannedTransaction::factory()->for($test->user)->for($test->account)->monthly()->create([
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-07-16',
    ]);

    return Transaction::factory()->for($test->user)->pending()->debit()->create([
        'account_id' => $transactionAccount->id,
        'amount' => -5000,
        'post_date' => '2026-07-15',
        'planned_transaction_id' => $plan->id,
        ...$transactionOverrides,
    ]);
}

it('counts every category line of a pending plan-matched split in the calendar to come', function () {
    $transaction = pendingMatchedPlan($this, $this->account);
    $transaction->splits()->create(['category_id' => null, 'amount' => 3000, 'position' => 1]);
    $transaction->splits()->create(['category_id' => null, 'amount' => 2000, 'position' => 2]);

    $component = Livewire::actingAs($this->user)->test(CalendarView::class);

    expect($component->get('plannedToCome'))->toBe(['income' => 0, 'spend' => 5000]);
});

it('keeps a plan in the calendar to come when its pending match is excluded', function () {
    pendingMatchedPlan($this, $this->account, ['excluded' => true]);

    $component = Livewire::actingAs($this->user)->test(CalendarView::class);

    expect($component->get('plannedToCome'))->toBe(['income' => 0, 'spend' => 5000]);
});

it('leaves a pending match on an excluded account out of the calendar to come', function () {
    $excluded = Account::factory()->for($this->user)->excludedFromReports()->create();
    pendingMatchedPlan($this, $excluded);

    $component = Livewire::actingAs($this->user)->test(CalendarView::class);

    expect($component->get('plannedToCome'))->toBe(['income' => 0, 'spend' => 0]);
});

it('leaves pending rows out of the insight totals and the median monthly spend', function () {
    $row = Transaction::factory()->for($this->user)->pending()->debit()->create([
        'account_id' => $this->account->id,
        'amount' => 7000,
        'post_date' => '2026-06-10',
    ]);
    $from = CarbonImmutable::parse('2026-06-01');
    $to = CarbonImmutable::parse('2026-06-30');

    expect(CountableFlow::total($this->user, TransactionDirection::Debit, $from, $to))->toBe(0)
        ->and(app(MedianMonthlySpend::class)->forUser($this->user))->toBe(0);

    $row->update(['status' => TransactionStatus::Posted]);

    expect(CountableFlow::total($this->user, TransactionDirection::Debit, $from, $to))->toBe(7000)
        ->and(app(MedianMonthlySpend::class)->forUser($this->user))->toBe(7000);
});
