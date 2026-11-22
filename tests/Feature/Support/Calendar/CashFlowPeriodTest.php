<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\CashFlowGrouping;
use App\Enums\DashboardWidget;
use App\Enums\TransactionDirection;
use App\Livewire\Dashboard\CashFlow;
use App\Livewire\Dashboard\Outflows;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reports\ReportAggregator;
use App\Support\Calendar\CashFlowGraphBuilder;
use App\Support\Calendar\CashFlowLoader;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-29 12:00:00'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create(['name' => 'Everyday']);
    $this->loader = app(CashFlowLoader::class);
});

function cfpRow(User $user, Account $account, string $date, int $amount, TransactionDirection $direction = TransactionDirection::Debit, ?Category $category = null, array $overrides = []): Transaction
{
    return Transaction::factory()->for($user)->for($account)->create(array_merge([
        'amount' => $amount,
        'direction' => $direction,
        'post_date' => $date,
        'category_id' => $category?->id,
        'transfer_pair_id' => null,
    ], $overrides));
}

function cfpNode(array $graph, string $id): ?array
{
    return collect($graph['nodes'])->firstWhere('id', $id);
}

function cfpRange(): array
{
    return [CarbonImmutable::parse('2026-05-01')->startOfDay(), CarbonImmutable::parse('2026-07-29')->endOfDay()];
}

it('builds a surplus node over a multi-month period', function () {
    $salary = Category::factory()->for($this->user)->create(['name' => 'Salary']);
    $food = Category::factory()->for($this->user)->create(['name' => 'Food']);
    cfpRow($this->user, $this->account, '2026-05-05', 100000, TransactionDirection::Credit, $salary);
    cfpRow($this->user, $this->account, '2026-06-05', 100000, TransactionDirection::Credit, $salary);
    cfpRow($this->user, $this->account, '2026-05-20', 50000, category: $food);
    cfpRow($this->user, $this->account, '2026-06-20', 50000, category: $food);
    cfpRow($this->user, $this->account, '2026-07-20', 50000, category: $food);

    [$start, $end] = cfpRange();
    $flow = $this->loader->load($this->user, $start, $end);

    expect($flow->income)->toBe(200000)
        ->and($flow->spending)->toBe(150000)
        ->and(cfpNode($flow->overview, CashFlowGraphBuilder::SURPLUS_ID)['value'])->toBe(50000)
        ->and(cfpNode($flow->overview, CashFlowGraphBuilder::DEFICIT_ID))->toBeNull();

    $july = $this->loader->load($this->user, CarbonImmutable::parse('2026-07-01'));

    expect($july->income)->toBe(0)
        ->and($july->spending)->toBe(50000);
});

it('draws no surplus when expenses meet or exceed income', function () {
    $salary = Category::factory()->for($this->user)->create(['name' => 'Salary']);
    $food = Category::factory()->for($this->user)->create(['name' => 'Food']);
    cfpRow($this->user, $this->account, '2026-06-05', 100000, TransactionDirection::Credit, $salary);
    cfpRow($this->user, $this->account, '2026-06-20', 120000, category: $food);

    [$start, $end] = cfpRange();
    $flow = $this->loader->load($this->user, $start, $end);

    expect(cfpNode($flow->overview, CashFlowGraphBuilder::SURPLUS_ID))->toBeNull()
        ->and(cfpNode($flow->overview, CashFlowGraphBuilder::DEFICIT_ID)['value'])->toBe(20000);

    cfpRow($this->user, $this->account, '2026-06-21', 20000, TransactionDirection::Credit, $salary);

    expect(cfpNode($this->loader->load($this->user, $start, $end)->overview, CashFlowGraphBuilder::SURPLUS_ID))->toBeNull();
});

it('groups cash flow by account', function () {
    $savings = Account::factory()->for($this->user)->create(['name' => 'Savings']);
    $food = Category::factory()->for($this->user)->create(['name' => 'Food']);
    cfpRow($this->user, $this->account, '2026-06-05', 30000, category: $food);
    cfpRow($this->user, $this->account, '2026-06-06', 5000);
    cfpRow($this->user, $savings, '2026-06-07', 20000, category: $food);

    [$start, $end] = cfpRange();
    $flow = $this->loader->load($this->user, $start, $end, CashFlowGrouping::Account);

    $everyday = cfpNode($flow->overview, 'expense_a'.$this->account->id);
    $saver = cfpNode($flow->overview, 'expense_a'.$savings->id);

    expect($everyday['name'])->toBe('Everyday')
        ->and($everyday['value'])->toBe(35000)
        ->and($saver['name'])->toBe('Savings')
        ->and($saver['value'])->toBe(20000)
        ->and($flow->spending)->toBe(55000)
        ->and(array_column($flow->outflows, 'name'))->toBe(['Everyday', 'Savings'])
        ->and($flow->drilldowns['expense_a'.$this->account->id]['nodes'])->toHaveCount(3);

    $byGroup = $this->loader->load($this->user, $start, $end, CashFlowGrouping::Group);

    expect($byGroup->spending)->toBe($flow->spending)
        ->and($byGroup->income)->toBe($flow->income);
});

it('builds cash flow from the user\'s own transactions only', function () {
    $stranger = User::factory()->create();
    $strangerAccount = Account::factory()->for($stranger)->create(['name' => 'Stranger']);
    cfpRow($this->user, $this->account, '2026-06-05', 10000);
    cfpRow($stranger, $strangerAccount, '2026-06-05', 99000);
    cfpRow($stranger, $strangerAccount, '2026-06-06', 77000, TransactionDirection::Credit);

    [$start, $end] = cfpRange();

    foreach (CashFlowGrouping::cases() as $grouping) {
        $flow = $this->loader->load($this->user, $start, $end, $grouping);

        expect($flow->spending)->toBe(10000)
            ->and($flow->income)->toBe(0)
            ->and(json_encode($flow->overview))->not->toContain('Stranger');
    }
});

it('counts the same rows as the reports page when grouped by account', function () {
    $untracked = Account::factory()->untracked()->for($this->user)->create();
    $tracked = Account::factory()->for($this->user)->create();
    $excluded = Account::factory()->for($this->user)->create(['exclude_from_reports' => true]);
    cfpRow($this->user, $this->account, '2026-06-05', 10000);
    cfpRow($this->user, $this->account, '2026-06-06', 99900, overrides: ['status' => 'pending']);
    cfpRow($this->user, $excluded, '2026-06-07', 77700);

    $transfer = function (Account $from, Account $to, int $amount): void {
        $debit = cfpRow($this->user, $from, '2026-06-08', $amount);
        $credit = cfpRow($this->user, $to, '2026-06-08', $amount, TransactionDirection::Credit);
        $credit->update(['transfer_pair_id' => $debit->id]);
        $debit->update(['transfer_pair_id' => $credit->id]);
    };
    $transfer($this->account, $untracked, 30000);
    $transfer($this->account, $tracked, 50000);

    [$start, $end] = cfpRange();
    $aggregator = app(ReportAggregator::class);
    $reportSpend = collect($aggregator->atoms($this->user, 'actual', $start, $end))->where('direction', 'debit')->sum('total');
    $accountSpend = collect($aggregator->accountAtoms($this->user, $start, $end))->where('direction', 'debit')->sum('total');
    $flow = $this->loader->load($this->user, $start, $end, CashFlowGrouping::Account);

    expect($accountSpend)->toBe((int) $reportSpend)
        ->and($flow->spending)->toBe(40000);
});

it('saves the cash flow grouping per user', function () {
    cfpRow($this->user, $this->account, '2026-07-20', 10000);
    $other = User::factory()->create();

    expect($this->user->preferences->cashFlowGrouping)->toBe(CashFlowGrouping::Group);

    Livewire::actingAs($this->user)->test(CashFlow::class)->call('setGrouping', 'account');

    expect($this->user->fresh()->preferences->cashFlowGrouping)->toBe(CashFlowGrouping::Account)
        ->and($other->fresh()->preferences->cashFlowGrouping)->toBe(CashFlowGrouping::Group);

    Livewire::actingAs($this->user->fresh())->test(Outflows::class)
        ->assertSeeHtml('aria-pressed="true" data-test="cash-flow-grouping-account"')
        ->assertSee('Everyday');

    $event = AuditEvent::query()->where('action', 'livewire.dashboard.cash-flow.setGrouping')->sole();

    expect($event->user_id)->toBe($this->user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success);

    Livewire::actingAs($this->user->fresh())->test(CashFlow::class)->call('setGrouping', 'nonsense');

    expect($this->user->fresh()->preferences->cashFlowGrouping)->toBe(CashFlowGrouping::Account);
});

it('drives both widgets from the dashboard period', function () {
    cfpRow($this->user, $this->account, '2026-07-27', 10000);
    cfpRow($this->user, $this->account, '2026-06-10', 40000);

    $this->user->updatePreferences(['dashboardPeriod' => '7d']);

    $cash = Livewire::actingAs($this->user)->test(CashFlow::class);
    $out = Livewire::actingAs($this->user)->test(Outflows::class);

    expect($cash->instance()->cashFlow()->spending)->toBe(10000)
        ->and($out->instance()->cashFlow()->spending)->toBe(10000);

    $this->user->updatePreferences(['dashboardPeriod' => '3m']);
    $cash->call('$refresh');

    expect($cash->instance()->cashFlow()->spending)->toBe(50000);
});

it('registers both widgets as lazy period-driven charts', function () {
    foreach ([DashboardWidget::CashFlow, DashboardWidget::Outflows] as $widget) {
        expect($widget->lazy())->toBeTrue()
            ->and($widget->followsPeriod())->toBeTrue()
            ->and($widget->isChart())->toBeTrue()
            ->and($widget->componentParams('stamp'))->toBe(['lazy' => true, 'periodStamp' => 'stamp']);
    }

    expect(DashboardWidget::CashFlow->component())->toBe('dashboard.cash-flow')
        ->and(DashboardWidget::Outflows->component())->toBe('dashboard.outflows');
});
