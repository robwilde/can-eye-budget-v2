<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\DashboardWidget;
use App\Enums\ReportSection;
use App\Enums\TradeActivity;
use App\Enums\TransactionDirection;
use App\Enums\TransactionStatus;
use App\Livewire\CategoryReport;
use App\Livewire\Dashboard;
use App\Livewire\Dashboard\InvestmentSummary;
use App\Livewire\Reports\InvestmentFlowsSection;
use App\Livewire\Reports\InvestmentPerformanceSection;
use App\Models\Account;
use App\Models\Holding;
use App\Models\Security;
use App\Models\SecurityPrice;
use App\Models\Trade;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 12:00:00'));
    config(['budget.investments.enabled' => true, 'services.exchange_rates.enabled' => false]);
    $this->user = User::factory()->create();
    $this->account = Account::factory()->investment()->for($this->user)->create(['balance' => 100000]);
    $this->security = Security::factory()->create(['currency' => 'AUD', 'ticker' => 'VAS']);
});

function investmentOwnHolding(mixed $test, ?Account $account = null, ?Security $security = null): Holding
{
    $security ??= $test->security;
    SecurityPrice::factory()->create(['security_id' => $security->id, 'currency' => 'AUD', 'date' => '2026-03-19', 'price' => 1100]);
    SecurityPrice::factory()->create(['security_id' => $security->id, 'currency' => 'AUD', 'date' => '2026-03-20', 'price' => 1200]);

    return Holding::factory()->create(['account_id' => ($account ?? $test->account)->id, 'security_id' => $security->id, 'currency' => 'AUD', 'quantity' => '10', 'amount' => 12000, 'cost_basis_per_share' => 1000, 'cost_basis_source' => 'manual', 'date' => '2026-03-20']);
}

function investmentWidgetKeys(array $widgets): array
{
    return array_map(static fn (DashboardWidget $widget): string => $widget->value, $widgets);
}

function investmentSale(mixed $test, TradeActivity $activity, string $quantity, int $price, string $date, string $currency = 'AUD'): Trade
{
    return Trade::factory()->create(['account_id' => $test->account->id, 'security_id' => $test->security->id, 'activity' => $activity, 'quantity' => $quantity, 'price' => $price, 'amount' => (int) round(abs((float) $quantity) * $price) * ($quantity[0] === '-' ? -1 : 1), 'currency' => $currency, 'date' => $date]);
}

function investmentFlowRow(mixed $test, int $amount, TransactionDirection $direction, string $date): Transaction
{
    return Transaction::factory()->create(['user_id' => $test->user->id, 'account_id' => $test->account->id, 'amount' => $amount, 'direction' => $direction, 'status' => TransactionStatus::Posted, 'post_date' => $date]);
}

it('hides the investment summary without investment accounts', function () {
    $plain = User::factory()->create();
    Account::factory()->for($plain)->create();

    $dashboard = Livewire::actingAs($plain->fresh())->test(Dashboard::class)->instance();

    expect(investmentWidgetKeys($dashboard->visibleWidgets()))->not->toContain('investment-summary');

    Livewire::actingAs($plain->fresh())->test(Dashboard::class)->call('hideWidget', 'investment-summary');

    expect(investmentWidgetKeys(Livewire::actingAs($plain->fresh())->test(Dashboard::class)->instance()->hiddenOffers()))->not->toContain('investment-summary');
});

it('ignores inactive, untracked and report-excluded investment accounts', function () {
    $user = User::factory()->create();

    foreach (['inactive', 'untracked', 'excludedFromReports'] as $state) {
        $account = Account::factory()->investment()->{$state}()->for($user)->create();
        investmentOwnHolding($this, $account, Security::factory()->create(['currency' => 'AUD']));
    }

    $dashboard = Livewire::actingAs($user->fresh())->test(Dashboard::class)->instance();
    $page = Livewire::actingAs($user->fresh())->test(CategoryReport::class)->instance();

    expect(investmentWidgetKeys($dashboard->visibleWidgets()))->not->toContain('investment-summary')
        ->and($page->sections)->not->toContain(ReportSection::InvestmentPerformance);
});

it('hides the investment summary while the flag is off or nothing is held', function () {
    investmentOwnHolding($this);
    config(['budget.investments.enabled' => false]);

    expect(investmentWidgetKeys(Livewire::actingAs($this->user->fresh())->test(Dashboard::class)->instance()->visibleWidgets()))->not->toContain('investment-summary');

    config(['budget.investments.enabled' => true]);
    Holding::query()->delete();

    expect(investmentWidgetKeys(Livewire::actingAs($this->user->fresh())->test(Dashboard::class)->instance()->visibleWidgets()))->not->toContain('investment-summary');
});

it('shows the investment summary for a held position and offers it back when hidden', function () {
    investmentOwnHolding($this);

    $dashboard = Livewire::actingAs($this->user->fresh())->test(Dashboard::class);

    expect(investmentWidgetKeys($dashboard->instance()->visibleWidgets()))->toContain('investment-summary');

    $dashboard->call('hideWidget', 'investment-summary');

    $reloaded = Livewire::actingAs($this->user->fresh())->test(Dashboard::class)->instance();

    expect(investmentWidgetKeys($reloaded->visibleWidgets()))->not->toContain('investment-summary')
        ->and(investmentWidgetKeys($reloaded->hiddenOffers()))->toContain('investment-summary');
});

it('draws the investment summary from the dashboard period', function () {
    investmentOwnHolding($this);
    SecurityPrice::factory()->create(['security_id' => $this->security->id, 'currency' => 'AUD', 'date' => '2026-02-25', 'price' => 800]);
    $this->user->updatePreferences(['dashboardPeriod' => 'custom', 'dashboardFrom' => '2026-03-01', 'dashboardTo' => '2026-03-20']);
    $stamp = DashboardPeriod::for($this->user->fresh())->stamp();

    $summary = Livewire::actingAs($this->user->fresh())->test(InvestmentSummary::class, ['periodStamp' => $stamp, 'lazy' => false]);
    $overview = $summary->instance()->overview;

    expect($overview['holdingsValue'])->toBe(12000)
        ->and($overview['portfolioValue'])->toBe(100000)
        ->and($overview['dayChange'])->toBe(1000)
        ->and($overview['periodReturnBp'])->toBe(5000);

    $summary->assertSee('VAS');
});

it('omits investment flows when there are none', function () {
    investmentOwnHolding($this);

    investmentFlowRow($this, 50000, TransactionDirection::Credit, '2026-02-10');

    $page = Livewire::actingAs($this->user)->test(CategoryReport::class);

    expect($page->instance()->sections)->toContain(ReportSection::InvestmentPerformance)
        ->and($page->instance()->sections)->not->toContain(ReportSection::InvestmentFlows);

    investmentFlowRow($this, 50000, TransactionDirection::Credit, '2026-03-10');

    expect(Livewire::actingAs($this->user)->test(CategoryReport::class)->instance()->sections)->toContain(ReportSection::InvestmentFlows);
});

it('leaves the investment sections out while the flag is off or without an investment account', function () {
    investmentFlowRow($this, 50000, TransactionDirection::Credit, '2026-03-10');
    config(['budget.investments.enabled' => false]);

    $off = Livewire::actingAs($this->user)->test(CategoryReport::class)->instance()->sections;

    expect($off)->not->toContain(ReportSection::InvestmentPerformance)->not->toContain(ReportSection::InvestmentFlows);

    config(['budget.investments.enabled' => true]);
    $plain = User::factory()->create();
    Account::factory()->for($plain)->create();

    $none = Livewire::actingAs($plain)->test(CategoryReport::class)->instance()->sections;

    expect($none)->not->toContain(ReportSection::InvestmentPerformance)->not->toContain(ReportSection::InvestmentFlows);
});

it('does not count sweeps out as sales', function () {
    investmentOwnHolding($this);
    investmentSale($this, TradeActivity::Buy, '20', 1000, '2026-01-10');
    investmentSale($this, TradeActivity::SweepOut, '-5', 1500, '2026-03-05');
    $sell = investmentSale($this, TradeActivity::Sell, '-5', 1500, '2026-03-06');

    $report = Livewire::actingAs($this->user)->test(InvestmentPerformanceSection::class, ['from' => '2026-03-01', 'to' => '2026-03-31'])->instance()->report;

    expect($report['sales'])->toHaveCount(1)
        ->and($report['sales'][0]['trade']->id)->toBe($sell->id)
        ->and($report['realised'])->toBe(2500)
        ->and($report['withheld'])->toBe(0);
});

it('withholds realised gain without an exchange rate', function () {
    investmentOwnHolding($this);
    investmentSale($this, TradeActivity::Buy, '10', 1000, '2026-02-04', 'NZD');
    investmentSale($this, TradeActivity::Sell, '-10', 1200, '2026-03-05', 'NZD');

    $component = Livewire::actingAs($this->user)->test(InvestmentPerformanceSection::class, ['from' => '2026-03-01', 'to' => '2026-03-31']);
    $report = $component->instance()->report;

    expect($report['sales'])->toHaveCount(1)
        ->and($report['sales'][0]['gain'])->toBeNull()
        ->and($report['realised'])->toBe(0)
        ->and($report['withheld'])->toBe(1);

    $component->assertSeeHtml('data-testid="investment-realised-withheld"');
});

it('builds investment sections from the user\'s own holdings', function () {
    investmentOwnHolding($this);
    $stranger = User::factory()->create();
    $theirAccount = Account::factory()->investment()->for($stranger)->create(['balance' => 900000]);
    $theirSecurity = Security::factory()->create(['currency' => 'AUD', 'ticker' => 'THEIRS']);
    investmentOwnHolding($this, $theirAccount, $theirSecurity);
    Trade::factory()->create(['account_id' => $theirAccount->id, 'security_id' => $theirSecurity->id, 'activity' => TradeActivity::Sell, 'quantity' => '-1', 'price' => 5000, 'amount' => -5000, 'date' => '2026-03-06']);
    Transaction::factory()->create(['user_id' => $stranger->id, 'account_id' => $theirAccount->id, 'amount' => 777700, 'direction' => TransactionDirection::Credit, 'status' => TransactionStatus::Posted, 'post_date' => '2026-03-10']);

    $performance = Livewire::actingAs($this->user)->test(InvestmentPerformanceSection::class, ['from' => '2026-03-01', 'to' => '2026-03-31'])->instance()->report;
    $flows = Livewire::actingAs($this->user)->test(InvestmentFlowsSection::class, ['from' => '2026-03-01', 'to' => '2026-03-31'])->instance()->flows;

    expect($performance['portfolioValue'])->toBe(100000)
        ->and(array_map(static fn (array $row): string => $row['security']->ticker, $performance['top']))->toBe(['VAS'])
        ->and($performance['sales'])->toBe([])
        ->and($flows)->toBe(['contributions' => 0, 'withdrawals' => 0, 'net' => 0]);
});

it('reports performance figures: return, contributions and top holdings', function () {
    investmentOwnHolding($this);
    investmentFlowRow($this, 50000, TransactionDirection::Credit, '2026-03-10');
    investmentFlowRow($this, 10000, TransactionDirection::Debit, '2026-03-12');
    investmentFlowRow($this, 99999, TransactionDirection::Credit, '2026-02-10');
    SecurityPrice::factory()->create(['security_id' => $this->security->id, 'currency' => 'AUD', 'date' => '2026-02-20', 'price' => 800]);

    $report = Livewire::actingAs($this->user)->test(InvestmentPerformanceSection::class, ['from' => '2026-03-01', 'to' => '2026-03-31'])->instance()->report;

    expect($report['totalReturnBp'])->toBe(2000)
        ->and($report['periodReturnBp'])->toBe(5000)
        ->and($report['unrealised'])->toBe(2000)
        ->and($report['contributions'])->toBe(50000)
        ->and($report['withdrawals'])->toBe(10000)
        ->and($report['top'])->toHaveCount(1);
});

it('renders the investment sections on the reports page', function () {
    investmentOwnHolding($this);
    investmentFlowRow($this, 50000, TransactionDirection::Credit, '2026-03-10');

    Livewire::actingAs($this->user)->test(CategoryReport::class)
        ->assertSeeHtml('data-testid="report-section-investment-performance"')
        ->assertSeeHtml('data-testid="report-section-investment-flows"');
});

it('ends the period return at the end of a past period', function () {
    investmentOwnHolding($this);
    SecurityPrice::factory()->create(['security_id' => $this->security->id, 'currency' => 'AUD', 'date' => '2026-02-10', 'price' => 1000]);
    SecurityPrice::factory()->create(['security_id' => $this->security->id, 'currency' => 'AUD', 'date' => '2026-02-25', 'price' => 1500]);

    $past = Livewire::actingAs($this->user)->test(InvestmentPerformanceSection::class, ['from' => '2026-02-15', 'to' => '2026-02-28'])->instance()->report;
    $current = Livewire::actingAs($this->user)->test(InvestmentPerformanceSection::class, ['from' => '2026-02-15', 'to' => '2026-03-31'])->instance()->report;

    expect($past['periodReturnBp'])->toBe(5000)
        ->and($current['periodReturnBp'])->toBe(2000);
});

it('leaves excluded transactions and transfers between investment accounts out of the flows', function () {
    investmentOwnHolding($this);
    $other = Account::factory()->investment()->for($this->user)->create();
    investmentFlowRow($this, 30000, TransactionDirection::Credit, '2026-03-10');
    investmentFlowRow($this, 70000, TransactionDirection::Credit, '2026-03-11')->update(['excluded' => true]);
    $leg = investmentFlowRow($this, 40000, TransactionDirection::Debit, '2026-03-12');
    $mirror = Transaction::factory()->create(['user_id' => $this->user->id, 'account_id' => $other->id, 'amount' => 40000, 'direction' => TransactionDirection::Credit, 'status' => TransactionStatus::Posted, 'post_date' => '2026-03-12']);
    $leg->update(['transfer_pair_id' => $mirror->id]);
    $mirror->update(['transfer_pair_id' => $leg->id]);

    $flows = Livewire::actingAs($this->user)->test(InvestmentFlowsSection::class, ['from' => '2026-03-01', 'to' => '2026-03-31'])->instance()->flows;

    expect($flows)->toBe(['contributions' => 30000, 'withdrawals' => 0, 'net' => 30000]);
});

it('keeps a hidden section\'s saved place when another section is moved', function () {
    investmentOwnHolding($this);
    $this->user->updatePreferences(['reportSectionOrder' => ['investment-flows', 'spending', 'net-worth', 'trends', 'investment-performance']]);

    Livewire::actingAs($this->user->fresh())->test(CategoryReport::class)->call('moveSection', 'trends', 'up');

    expect($this->user->fresh()->preferences->reportSectionOrder)->toBe(['investment-flows', 'spending', 'trends', 'net-worth', 'investment-performance']);

    Livewire::actingAs($this->user->fresh())->test(CategoryReport::class)->call('moveSection', 'investment-flows', 'down');

    expect($this->user->fresh()->preferences->reportSectionOrder)->toBe(['investment-flows', 'spending', 'trends', 'net-worth', 'investment-performance']);
});

it('drops a dragged widget where it was dropped while the investment widget is unavailable', function () {
    $dashboard = Livewire::actingAs($this->user->fresh())->test(Dashboard::class);
    $shown = investmentWidgetKeys($dashboard->instance()->visibleWidgets());

    $dashboard->call('reorder', $shown[0], 8);

    $after = investmentWidgetKeys(Livewire::actingAs($this->user->fresh())->test(Dashboard::class)->instance()->visibleWidgets());

    expect($after[8])->toBe($shown[0])
        ->and($after[7])->toBe($shown[8]);
});
