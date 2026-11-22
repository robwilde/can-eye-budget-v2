<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ChartGranularity;
use App\Enums\ChartHeight;
use App\Enums\DashboardWidget;
use App\Enums\TransactionPeriod;
use App\Enums\WidgetWidth;
use App\Livewire\Dashboard;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00'));
    $this->user = User::factory()->withPayCycle()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->user->update(['primary_account_id' => $this->account->id]);
});

function dashboardFor(User $user): Testable
{
    return Livewire::actingAs($user->fresh())->test(Dashboard::class);
}

function widgetKeysOf(array $widgets): array
{
    return array_map(static fn (DashboardWidget $widget): string => $widget->value, $widgets);
}

function storeRawPreferences(User $user, array $preferences): void
{
    DB::table('users')->where('id', $user->id)->update(['preferences' => json_encode($preferences)]);
}

function plannedTransactionQueries(User $user): int
{
    DB::enableQueryLog();
    DB::flushQueryLog();
    dashboardFor($user);

    return collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'planned_transactions'))->count();
}

it('keeps the saved order and lists a hidden widget', function () {
    dashboardFor($this->user)->call('reorder', 'recent-spending', 0)->call('hideWidget', 'monthly-projection');

    $reloaded = dashboardFor($this->user);
    $visible = widgetKeysOf($reloaded->instance()->visibleWidgets());

    expect($visible[0])->toBe('recent-spending')
        ->and($visible)->not->toContain('monthly-projection')
        ->and(widgetKeysOf($reloaded->instance()->hiddenOffers()))->toBe(['monthly-projection']);

    $html = $reloaded->call('toggleCustomise')
        ->assertSeeHtml('data-testid="widget-show-monthly-projection"')
        ->html();

    expect(mb_strpos($html, 'data-widget="recent-spending"'))->toBeLessThan(mb_strpos($html, 'data-widget="pay-summary"'));
});

it('runs no queries for hidden widgets', function () {
    dashboardFor($this->user)->assertSeeLivewire('dashboard.monthly-projection');

    dashboardFor($this->user)->call('hideWidget', 'monthly-projection');
    dashboardFor($this->user)->assertDontSeeLivewire('dashboard.monthly-projection');

    PlannedTransaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'start_date' => '2026-03-20']);

    $shown = plannedTransactionQueries($this->user);
    dashboardFor($this->user)->call('hideWidget', 'upcoming-plans');
    dashboardFor($this->user)->assertDontSee('Next 3 planned');

    expect(plannedTransactionQueries($this->user))->toBeLessThan($shown);
});

it('stores the dashboard layout per user', function () {
    $other = User::factory()->withPayCycle()->create();

    dashboardFor($this->user)->call('hideWidget', 'net-worth-chart')->call('setWidth', 'pay-summary', 'half')->call('setTwoColumn', false);

    $mine = $this->user->fresh()->preferences->dashboardLayout;
    $theirs = $other->fresh()->preferences->dashboardLayout;

    expect($mine->isHidden(DashboardWidget::NetWorthChart))->toBeTrue()
        ->and($mine->width(DashboardWidget::PaySummary))->toBe(WidgetWidth::Half)
        ->and($mine->twoColumn)->toBeFalse()
        ->and($theirs->hidden)->toBe([])
        ->and($theirs->twoColumn)->toBeTrue();

    expect(widgetKeysOf(dashboardFor($other)->instance()->visibleWidgets()))->toContain('net-worth-chart')
        ->and(widgetKeysOf(dashboardFor($this->user)->instance()->visibleWidgets()))->not->toContain('net-worth-chart');
});

it('falls back to the last 30 days for an unknown saved period', function () {
    storeRawPreferences($this->user, ['dashboardPeriod' => 'no-longer-exists']);

    $period = DashboardPeriod::for($this->user->fresh());

    expect($period->period)->toBe(TransactionPeriod::ThirtyDays)
        ->and($period->start->toDateString())->toBe('2026-02-08')
        ->and($period->end->toDateString())->toBe('2026-03-10');
});

it('falls back to the last 30 days for a bad or backwards custom range', function () {
    foreach ([['2026-03-05', '2026-03-01'], ['not-a-date', '2026-03-01'], [null, null]] as [$from, $to]) {
        storeRawPreferences($this->user, ['dashboardPeriod' => 'custom', 'dashboardFrom' => $from, 'dashboardTo' => $to]);

        expect(DashboardPeriod::for($this->user->fresh())->period)->toBe(TransactionPeriod::ThirtyDays);
    }

    storeRawPreferences($this->user, ['dashboardPeriod' => 'custom', 'dashboardFrom' => '2026-01-01', 'dashboardTo' => '2026-01-31']);

    $period = DashboardPeriod::for($this->user->fresh());

    expect($period->period)->toBe(TransactionPeriod::Custom)
        ->and($period->start->toDateString())->toBe('2026-01-01')
        ->and($period->end->toDateString())->toBe('2026-01-31');
});

it('does not offer a period the selector does not list', function () {
    storeRawPreferences($this->user, ['dashboardPeriod' => 'pay-cycle']);

    expect(DashboardPeriod::for($this->user->fresh())->period)->toBe(TransactionPeriod::ThirtyDays);
});

it('uses weekly points for ranges over a year and up to five years', function () {
    storeRawPreferences($this->user, ['dashboardPeriod' => 'custom', 'dashboardFrom' => '2024-01-01', 'dashboardTo' => '2026-01-01']);

    expect(DashboardPeriod::for($this->user->fresh())->granularity)->toBe(ChartGranularity::Weekly);

    $granularity = function (string $period): ChartGranularity {
        storeRawPreferences($this->user, ['dashboardPeriod' => $period]);

        return DashboardPeriod::for($this->user->fresh())->granularity;
    };

    expect($granularity('1d'))->toBe(ChartGranularity::Daily)
        ->and($granularity('1y'))->toBe(ChartGranularity::Daily)
        ->and($granularity('5y'))->toBe(ChartGranularity::Weekly)
        ->and($granularity('10y'))->toBe(ChartGranularity::Monthly);
});

it('starts all one month before the first transaction', function () {
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'post_date' => '2024-06-15']);
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'post_date' => '2025-02-01']);
    Transaction::factory()->create(['post_date' => '2020-01-01']);

    $range = TransactionPeriod::All->resolve($this->user);

    expect($range['start']->toDateString())->toBe('2024-05-15')
        ->and($range['end']->toDateString())->toBe('2026-03-10');
});

it('starts all five years back when there is no first transaction or it is too recent', function () {
    expect(TransactionPeriod::All->resolve($this->user)['start']->toDateString())->toBe('2021-03-10');

    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'post_date' => '2026-04-10']);

    expect(TransactionPeriod::All->resolve($this->user)['start']->toDateString())->toBe('2021-03-10');

    Transaction::query()->where('user_id', $this->user->id)->delete();
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'post_date' => '2026-04-09']);

    expect(TransactionPeriod::All->resolve($this->user)['start']->toDateString())->toBe('2026-03-09');
});

it('does not overflow when all starts a month before a month-end first transaction', function () {
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'post_date' => '2024-03-31']);

    expect(TransactionPeriod::All->resolve($this->user)['start']->toDateString())->toBe('2024-02-29');
});

it('gives every new period a concrete start and end', function () {
    $ranges = fn (TransactionPeriod $period): array => array_map(
        static fn (CarbonImmutable $date): string => $date->toDateString(),
        $period->resolve($this->user),
    );

    expect($ranges(TransactionPeriod::OneDay))->toBe(['start' => '2026-03-09', 'end' => '2026-03-10'])
        ->and($ranges(TransactionPeriod::WeekToDate))->toBe(['start' => '2026-03-09', 'end' => '2026-03-10'])
        ->and($ranges(TransactionPeriod::ThisMonth))->toBe(['start' => '2026-03-01', 'end' => '2026-03-10'])
        ->and($ranges(TransactionPeriod::LastMonth))->toBe(['start' => '2026-02-01', 'end' => '2026-02-28'])
        ->and($ranges(TransactionPeriod::ThirtyDays))->toBe(['start' => '2026-02-08', 'end' => '2026-03-10'])
        ->and($ranges(TransactionPeriod::ThreeMonths))->toBe(['start' => '2025-12-10', 'end' => '2026-03-10'])
        ->and($ranges(TransactionPeriod::YearToDate))->toBe(['start' => '2026-01-01', 'end' => '2026-03-10'])
        ->and($ranges(TransactionPeriod::OneYear))->toBe(['start' => '2025-03-10', 'end' => '2026-03-10'])
        ->and($ranges(TransactionPeriod::FiveYears))->toBe(['start' => '2021-03-10', 'end' => '2026-03-10'])
        ->and($ranges(TransactionPeriod::TenYears))->toBe(['start' => '2016-03-10', 'end' => '2026-03-10']);
});

it('keeps the existing period cases unchanged for the reports', function () {
    $range = TransactionPeriod::ThisMonth->dateRange($this->user);

    expect($range['end'])->toBeNull()
        ->and($range['start']->toDateString())->toBe('2026-03-01')
        ->and(TransactionPeriod::All->dateRange($this->user))->toBe(['start' => null, 'end' => null]);
});

it('saves the chosen period per user and audits it', function () {
    $other = User::factory()->create();

    dashboardFor($this->user)->call('setPeriod', '3m')->assertDispatched('dashboard-period-changed');

    expect($this->user->fresh()->preferences->dashboardPeriod)->toBe(TransactionPeriod::ThreeMonths)
        ->and($other->fresh()->preferences->dashboardPeriod)->toBe(TransactionPeriod::ThirtyDays);

    $event = AuditEvent::query()->where('action', 'livewire.dashboard.setPeriod')->sole();

    expect($event->user_id)->toBe($this->user->id);

    expect(dashboardFor($this->user)->instance()->period()->period)->toBe(TransactionPeriod::ThreeMonths);
});

it('ignores a period key the selector does not offer', function () {
    dashboardFor($this->user)->call('setPeriod', 'pay-cycle')->call('setPeriod', 'nonsense');

    expect($this->user->fresh()->preferences->dashboardPeriod)->toBe(TransactionPeriod::ThirtyDays);
});

it('saves a custom range and falls back to 30 days for an invalid one', function () {
    $component = dashboardFor($this->user)
        ->call('setPeriod', 'custom')
        ->assertSet('showCustomRange', true)
        ->set('customFrom', '2026-01-01')
        ->set('customTo', '2026-02-01')
        ->call('setCustomRange');

    $saved = $this->user->fresh()->preferences;

    expect($saved->dashboardPeriod)->toBe(TransactionPeriod::Custom)
        ->and($saved->dashboardFrom)->toBe('2026-01-01')
        ->and($saved->dashboardTo)->toBe('2026-02-01');

    $component->set('customFrom', '2026-03-01')->set('customTo', '2026-01-01')->call('setCustomRange');

    expect($this->user->fresh()->preferences->dashboardPeriod)->toBe(TransactionPeriod::ThirtyDays)
        ->and($this->user->fresh()->preferences->dashboardFrom)->toBeNull();
});

it('shows the saved custom range with its dates when the dashboard loads', function () {
    storeRawPreferences($this->user, ['dashboardPeriod' => 'custom', 'dashboardFrom' => '2026-01-01', 'dashboardTo' => '2026-02-01']);

    dashboardFor($this->user)
        ->assertSet('showCustomRange', true)
        ->assertSet('customFrom', '2026-01-01')
        ->assertSeeHtml('data-testid="dashboard-custom-range"')
        ->assertSee('1 Jan 2026 to 1 Feb 2026');
});

it('lists the period selector with the saved period marked', function () {
    $component = dashboardFor($this->user)->call('setPeriod', 'ytd');

    expect($component->html())->toMatch('/class="report-chip active"\s+data-testid="dashboard-period-ytd"/')
        ->and($component->html())->not->toMatch('/class="report-chip active"\s+data-testid="dashboard-period-last-30-days"/');
});

it('collapses a widget to a bar and expands it again', function () {
    dashboardFor($this->user)
        ->call('toggleCollapsed', 'recent-spending')
        ->assertSeeHtml('data-testid="widget-toolbar-recent-spending"')
        ->assertDontSee('Spend last 7 days')
        ->call('toggleCollapsed', 'recent-spending')
        ->assertSee('Spend last 7 days');
});

it('collapsed widgets run no queries', function () {
    PlannedTransaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'start_date' => '2026-03-20']);

    $expanded = plannedTransactionQueries($this->user);
    dashboardFor($this->user)->call('toggleCollapsed', 'upcoming-plans');

    expect(plannedTransactionQueries($this->user))->toBeLessThan($expanded);
});

it('reorders widgets, sets widths and heights, and resets the layout', function () {
    $component = dashboardFor($this->user)
        ->call('reorder', 'budgets-this-cycle', 1)
        ->call('setWidth', 'pay-summary', 'half')
        ->call('setChartHeight', 'net-worth-chart', 'tall')
        ->call('setChartHeight', 'pay-summary', 'tall');

    $layout = $this->user->fresh()->preferences->dashboardLayout;

    expect(widgetKeysOf($layout->visible())[1])->toBe('budgets-this-cycle')
        ->and($layout->width(DashboardWidget::PaySummary))->toBe(WidgetWidth::Half)
        ->and($layout->height(DashboardWidget::NetWorthChart))->toBe(ChartHeight::Tall)
        ->and($layout->heights)->toBe(['net-worth-chart' => 'tall']);

    $component->call('resetLayout');

    expect($this->user->fresh()->preferences->dashboardLayout->order)->toBe([]);
});

it('ignores unknown widget keys and values from the client', function () {
    dashboardFor($this->user)
        ->call('hideWidget', 'nonsense')
        ->call('showWidget', 'nonsense')
        ->call('toggleCollapsed', 'nonsense')
        ->call('reorder', 'nonsense', 0)
        ->call('setWidth', 'pay-summary', 'giant')
        ->call('setWidth', 'nonsense', 'half');

    expect($this->user->fresh()->preferences->dashboardLayout->hidden)->toBe([])
        ->and($this->user->fresh()->preferences->dashboardLayout->widths)->toBe([]);
});

it('does not offer a hidden widget that has no data', function () {
    dashboardFor($this->user)->call('hideWidget', 'statements-due')->call('hideWidget', 'pending-transfers')->call('hideWidget', 'insights');

    expect(widgetKeysOf(dashboardFor($this->user)->instance()->hiddenOffers()))->toBe([]);

    dashboardFor($this->user)->call('toggleCustomise')->assertSee('Nothing is hidden.');
});

it('offers every hidden widget that does have data', function () {
    dashboardFor($this->user)->call('hideWidget', 'net-worth-chart')->call('hideWidget', 'pay-summary');

    expect(widgetKeysOf(dashboardFor($this->user)->instance()->hiddenOffers()))->toBe(['pay-summary', 'net-worth-chart']);
});

it('renders widgets full width in two columns and in one column', function () {
    dashboardFor($this->user)
        ->assertSeeHtml('lg:grid-cols-2')
        ->assertSeeHtml('lg:col-span-2')
        ->call('setTwoColumn', false)
        ->assertDontSeeHtml('lg:grid-cols-2');
});

it('shows the toolbar only in customise mode and keeps that mode out of the saved layout', function () {
    dashboardFor($this->user)
        ->assertDontSeeHtml('data-testid="widget-handle-pay-summary"')
        ->call('toggleCustomise')
        ->assertSeeHtml('data-testid="widget-handle-pay-summary"')
        ->assertSeeHtml('data-testid="widget-width-pay-summary-half"')
        ->assertSeeHtml('data-testid="widget-height-net-worth-chart-tall"')
        ->assertDontSeeHtml('data-testid="widget-height-pay-summary-tall"');

    expect($this->user->fresh()->preferences->dashboardLayout->hidden)->toBe([]);
});

it('hands a widget that follows the period a stamp that changes with the period', function () {
    $before = DashboardPeriod::for($this->user)->stamp();

    $this->user->updatePreferences(['dashboardPeriod' => '7d']);

    expect(DashboardPeriod::for($this->user->fresh())->stamp())->not->toBe($before);
});

it('mounts child widgets lazily or with the period stamp as the registry says', function () {
    $stamp = DashboardPeriod::for($this->user)->stamp();

    expect(DashboardWidget::MonthlyProjection->componentParams($stamp))->toBe(['lazy' => true])
        ->and(DashboardWidget::NetWorthChart->componentParams($stamp))->toBe(['lazy' => true, 'periodStamp' => $stamp])
        ->and(DashboardWidget::BalanceSheet->componentParams($stamp))->toBe(['lazy' => true])
        ->and(DashboardWidget::PayCycleCalendar->componentParams($stamp))->toBe([])
        ->and(DashboardWidget::NetWorthChart->component())->toBe('dashboard.net-worth-chart')
        ->and(DashboardWidget::BalanceSheet->component())->toBe('dashboard.balance-sheet')
        ->and(DashboardWidget::PaySummary->component())->toBeNull()
        ->and(DashboardWidget::PaySummary->view())->toBe('livewire.dashboard.widgets.pay-summary');
});
