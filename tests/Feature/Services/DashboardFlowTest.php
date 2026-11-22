<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\DashboardWidget;
use App\Enums\TransactionDirection;
use App\Livewire\Dashboard\MoneyFlow;
use App\Livewire\Dashboard\SpendingTrend;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reports\DashboardFlowService;
use App\Services\Reports\ReportAggregator;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->service = app(DashboardFlowService::class);
});

function dashFlowRow(User $user, Account $account, string $date, int $amount, TransactionDirection $direction = TransactionDirection::Debit, ?Category $category = null, array $overrides = []): Transaction
{
    return Transaction::factory()->for($user)->for($account)->create(array_merge([
        'amount' => $amount,
        'direction' => $direction,
        'post_date' => $date,
        'category_id' => $category?->id,
        'transfer_pair_id' => null,
    ], $overrides));
}

function dashFlowTransfer(User $user, Account $from, Account $to, string $date, int $amount): void
{
    $debit = dashFlowRow($user, $from, $date, $amount);
    $credit = dashFlowRow($user, $to, $date, $amount, TransactionDirection::Credit);
    $credit->update(['transfer_pair_id' => $debit->id]);
    $debit->update(['transfer_pair_id' => $credit->id]);
}

function dashFlowMonth(array $bars, string $key): array
{
    return collect($bars)->firstWhere('key', $key);
}

it('counts only tracked to untracked transfers in money flow', function () {
    $untracked = Account::factory()->untracked()->for($this->user)->create();
    $otherTracked = Account::factory()->for($this->user)->create();
    dashFlowTransfer($this->user, $this->account, $untracked, '2026-03-04', 30000);
    dashFlowTransfer($this->user, $this->account, $otherTracked, '2026-03-05', 50000);

    dashFlowTransfer($this->user, $untracked, $this->account, '2026-03-06', 20000);

    $month = CarbonImmutable::parse('2026-03-01');
    $march = dashFlowMonth($this->service->moneyFlow($this->user, $month, null), '2026-03');

    expect($march['expense'])->toBe(30000)
        ->and($march['income'])->toBe(20000)
        ->and($this->service->spendingTrend($this->user, $month, null)['currentTotal'])->toBe(30000);
});

it('clamps future months and marks the current month partial', function () {
    dashFlowRow($this->user, $this->account, '2026-03-02', 1000);
    dashFlowRow($this->user, $this->account, '2026-03-20', 4000);
    dashFlowRow($this->user, $this->account, '2026-02-02', 2000);

    $component = Livewire::actingAs($this->user)
        ->test(MoneyFlow::class)
        ->set('month', '2027-01')
        ->assertSet('month', '')
        ->assertSeeHtml('data-test="money-flow-partial"');

    $bars = $component->instance()->bars();

    expect(array_column($bars, 'key'))->toBe(['2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03'])
        ->and(array_column($bars, 'partial'))->toBe([false, false, false, false, false, true])
        ->and(dashFlowMonth($bars, '2026-03')['expense'])->toBe(1000)
        ->and(dashFlowMonth($bars, '2026-02')['expense'])->toBe(2000);

    expect($this->service->resolveMonth($this->user, 'nonsense')->format('Y-m'))->toBe('2026-03')
        ->and($this->service->resolveMonth($this->user, '2025-12')->format('Y-m'))->toBe('2025-12');
});

it('marks no month partial when an earlier month is selected', function () {
    $bars = $this->service->moneyFlow($this->user, CarbonImmutable::parse('2026-01-01'), null);

    expect(array_column($bars, 'partial'))->toBe([false, false, false, false, false, false]);
});

it('compares the same number of elapsed days in the trend delta', function () {
    dashFlowRow($this->user, $this->account, '2026-03-03', 2000);
    dashFlowRow($this->user, $this->account, '2026-03-10', 500);
    dashFlowRow($this->user, $this->account, '2026-02-05', 1000);
    dashFlowRow($this->user, $this->account, '2026-02-10', 300);
    dashFlowRow($this->user, $this->account, '2026-02-11', 70);
    dashFlowRow($this->user, $this->account, '2026-02-15', 5000);

    $trend = $this->service->spendingTrend($this->user, CarbonImmutable::parse('2026-03-01'), null);

    expect($trend['elapsed'])->toBe(10)
        ->and($trend['currentTotal'])->toBe(2500)
        ->and($trend['previousTotal'])->toBe(1300)
        ->and($trend['delta'])->toBe(1200)
        ->and($trend['current'])->toHaveCount(10)
        ->and($trend['previous'])->toHaveCount(31)
        ->and($trend['previous'][4])->toBe(1000)
        ->and($trend['previous'][30])->toBe(6370);
});

it('nets the previous month over the same elapsed days as the current one', function () {
    $groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries']);
    dashFlowRow($this->user, $this->account, '2026-02-03', 6000, category: $groceries);
    dashFlowRow($this->user, $this->account, '2026-02-20', 8000, TransactionDirection::Credit, $groceries);

    $trend = $this->service->spendingTrend($this->user, CarbonImmutable::parse('2026-03-01'), null);

    expect($trend['previousTotal'])->toBe(6000)
        ->and($trend['previous'][30])->toBe(0);

    $refundFirst = User::factory()->create();
    $refundAccount = Account::factory()->for($refundFirst)->create();
    $dining = Category::factory()->for($refundFirst)->create(['name' => 'Dining']);
    dashFlowRow($refundFirst, $refundAccount, '2026-02-05', 1000, TransactionDirection::Credit, $dining);
    dashFlowRow($refundFirst, $refundAccount, '2026-02-15', 5000, category: $dining);

    $other = $this->service->spendingTrend($refundFirst, CarbonImmutable::parse('2026-03-01'), null);

    expect($other['previousTotal'])->toBe(0)
        ->and($other['previous'][30])->toBe(4000);
});

it('compares all of a shorter previous month when today is past its last day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-30 12:00:00'));
    dashFlowRow($this->user, $this->account, '2026-02-28', 3000);
    dashFlowRow($this->user, $this->account, '2026-03-29', 1000);

    $trend = $this->service->spendingTrend($this->user, CarbonImmutable::parse('2026-03-01'), null);

    expect($trend['elapsed'])->toBe(30)
        ->and($trend['previousTotal'])->toBe(3000)
        ->and($trend['delta'])->toBe(-2000);
});

it('folds the extra days of a longer previous month into the last point', function () {
    dashFlowRow($this->user, $this->account, '2026-01-10', 1000);
    dashFlowRow($this->user, $this->account, '2026-01-30', 4000);
    dashFlowRow($this->user, $this->account, '2026-02-02', 700);

    $trend = $this->service->spendingTrend($this->user, CarbonImmutable::parse('2026-02-01'), null);

    expect($trend['days'])->toBe(28)
        ->and($trend['elapsed'])->toBe(28)
        ->and($trend['previous'])->toHaveCount(28)
        ->and($trend['previous'][26])->toBe(1000)
        ->and($trend['previous'][27])->toBe(5000)
        ->and($trend['previousTotal'])->toBe(1000)
        ->and($trend['delta'])->toBe(-300);
});

it('includes child categories when filtering by a parent', function () {
    $parent = Category::factory()->for($this->user)->create(['name' => 'Food']);
    $childA = Category::factory()->for($this->user)->withParent($parent)->create(['name' => 'Cafe']);
    $childB = Category::factory()->for($this->user)->withParent($parent)->create(['name' => 'Groceries']);
    $other = Category::factory()->for($this->user)->create(['name' => 'Fuel']);
    dashFlowRow($this->user, $this->account, '2026-03-01', 500, category: $parent);
    dashFlowRow($this->user, $this->account, '2026-03-02', 1000, category: $childA);
    dashFlowRow($this->user, $this->account, '2026-03-03', 2000, category: $childB);
    dashFlowRow($this->user, $this->account, '2026-03-04', 4000, category: $other);

    $month = CarbonImmutable::parse('2026-03-01');

    expect($this->service->spendingTrend($this->user, $month, $parent->id)['currentTotal'])->toBe(3500)
        ->and($this->service->spendingTrend($this->user, $month, $childA->id)['currentTotal'])->toBe(1000)
        ->and($this->service->spendingTrend($this->user, $month, null)['currentTotal'])->toBe(7500);
});

it('shows only the user\'s own transactions in flow widgets', function () {
    $stranger = User::factory()->create();
    $strangerAccount = Account::factory()->for($stranger)->create();
    $strangerCategory = Category::factory()->for($stranger)->create();
    dashFlowRow($this->user, $this->account, '2026-03-02', 1000);
    dashFlowRow($stranger, $strangerAccount, '2026-03-02', 99000, category: $strangerCategory);

    $month = CarbonImmutable::parse('2026-03-01');

    expect(dashFlowMonth($this->service->moneyFlow($this->user, $month, null), '2026-03')['expense'])->toBe(1000)
        ->and(dashFlowMonth($this->service->moneyFlow($this->user, $month, $strangerAccount->id), '2026-03')['expense'])->toBe(1000)
        ->and($this->service->spendingTrend($this->user, $month, $strangerCategory->id)['currentTotal'])->toBe(1000);

    $second = Account::factory()->for($this->user)->create();
    dashFlowRow($this->user, $second, '2026-03-03', 2000);

    expect(dashFlowMonth($this->service->moneyFlow($this->user, $month, $second->id), '2026-03')['expense'])->toBe(2000)
        ->and(dashFlowMonth($this->service->moneyFlow($this->user, $month, $this->account->id), '2026-03')['expense'])->toBe(1000);

    Livewire::actingAs($this->user)
        ->test(MoneyFlow::class, ['account' => (string) $strangerAccount->id])
        ->assertDontSee('99,000')
        ->assertDontSee('990.00');
});

it('totals the same as the reports page', function () {
    $groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries']);
    $excluded = Account::factory()->for($this->user)->create(['exclude_from_reports' => true]);
    dashFlowRow($this->user, $this->account, '2026-03-02', 6000, category: $groceries);
    dashFlowRow($this->user, $this->account, '2026-03-03', 1000, TransactionDirection::Credit, $groceries);
    dashFlowRow($this->user, $this->account, '2026-03-04', 8000, TransactionDirection::Credit);
    dashFlowRow($this->user, $this->account, '2026-03-05', 9900, overrides: ['status' => 'pending']);
    dashFlowRow($this->user, $excluded, '2026-03-06', 7700);

    $aggregator = app(ReportAggregator::class);
    $atoms = $aggregator->netRefunds($aggregator->atoms($this->user, 'actual', CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-10')->endOfDay()), byMonth: true);
    $reportExpense = collect($atoms)->where('direction', 'debit')->sum('total');
    $reportIncome = collect($atoms)->where('direction', 'credit')->sum('total');

    $march = dashFlowMonth($this->service->moneyFlow($this->user, CarbonImmutable::parse('2026-03-01'), null), '2026-03');
    $trend = $this->service->spendingTrend($this->user, CarbonImmutable::parse('2026-03-01'), null);

    expect($march['expense'])->toBe(5000)
        ->and($march['expense'])->toBe((int) $reportExpense)
        ->and($march['income'])->toBe((int) $reportIncome)
        ->and($march['income'])->toBe(8000)
        ->and($trend['currentTotal'])->toBe(5000);
});

it('registers both widgets as lazy half width charts that keep their own period', function () {
    foreach ([DashboardWidget::MoneyFlow, DashboardWidget::SpendingTrend] as $widget) {
        expect($widget->lazy())->toBeTrue()
            ->and($widget->isChart())->toBeTrue()
            ->and($widget->followsPeriod())->toBeFalse()
            ->and($widget->defaultWidth()->value)->toBe('half')
            ->and($widget->hasData($this->user))->toBeFalse();
    }

    expect(DashboardWidget::MoneyFlow->component())->toBe('dashboard.money-flow')
        ->and(DashboardWidget::SpendingTrend->component())->toBe('dashboard.spending-trend');

    dashFlowRow($this->user, $this->account, '2026-03-02', 1000);

    expect(DashboardWidget::MoneyFlow->hasData($this->user))->toBeTrue();
});

it('keeps each widget\'s month and filter in the URL', function () {
    foreach ([[MoneyFlow::class, 'month', 'flowMonth'], [MoneyFlow::class, 'account', 'flowAccount'], [SpendingTrend::class, 'month', 'trendMonth'], [SpendingTrend::class, 'category', 'trendCategory']] as [$class, $property, $name]) {
        $attribute = (new ReflectionProperty($class, $property))->getAttributes(Url::class)[0]->newInstance();

        expect($attribute->as)->toBe($name);
    }
});

it('shows the current month in the pickers until another is chosen', function () {
    Livewire::actingAs($this->user)->test(MoneyFlow::class)->assertSet('month', '')->assertSeeHtml('value="2026-03"');
    Livewire::actingAs($this->user)->test(SpendingTrend::class)->assertSet('month', '')->assertSeeHtml('value="2026-03"');
    Livewire::actingAs($this->user)->test(SpendingTrend::class, ['month' => '2026-01'])->assertSet('month', '2026-01')->assertSee('January 2026');
    Livewire::actingAs($this->user)->test(SpendingTrend::class)->set('month', '2026-03')->assertSet('month', '');
});

it('orders the trend categories under their parents', function () {
    $food = Category::factory()->for($this->user)->create(['name' => 'Food']);
    Category::factory()->for($this->user)->create(['name' => 'Bills']);
    Category::factory()->for($this->user)->withParent($food)->create(['name' => 'Cafe']);

    $names = Livewire::actingAs($this->user)->test(SpendingTrend::class)->instance()->categories()->pluck('name')->all();

    expect($names)->toBe(['Bills', 'Food', 'Cafe']);
});

it('draws the current line when only one day has elapsed', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 12:00:00'));
    dashFlowRow($this->user, $this->account, '2026-03-01', 1000);

    $html = Livewire::actingAs($this->user)->test(SpendingTrend::class)->html();

    expect(preg_match('/points="(0,[\d.]+) \1" data-test="spending-trend-current"/', $html))->toBe(1);
});
