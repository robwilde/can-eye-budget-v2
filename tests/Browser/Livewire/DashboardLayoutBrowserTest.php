<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\DashboardWidget;
use App\Enums\TransactionPeriod;
use App\Models\Account;
use App\Models\AccountDailyBalance;
use App\Models\PlannedTransaction;
use App\Models\User;

function dashboardUser(): User
{
    $user = User::factory()->withPayCycle()->create();
    $account = Account::factory()->for($user)->create(['balance' => 500000]);
    $user->update(['primary_account_id' => $account->id]);
    PlannedTransaction::factory()->for($user)->create(['account_id' => $account->id, 'description' => 'Gym membership', 'start_date' => now()->addDays(5)]);

    return $user;
}

test('picking a period marks it and saves it for the next visit', function () {
    $user = dashboardUser();
    $this->actingAs($user);

    visit('/dashboard')
        ->assertPresent('[data-testid="dashboard-period-last-30-days"].active')
        ->click('[data-testid="dashboard-period-ytd"]')
        ->assertPresent('[data-testid="dashboard-period-ytd"].active')
        ->assertSeeIn('[data-testid="dashboard-period-label"]', 'Year to Date')
        ->assertNoJavaScriptErrors();

    expect($user->fresh()->preferences->dashboardPeriod)->toBe(TransactionPeriod::YearToDate);

    visit('/dashboard')->assertPresent('[data-testid="dashboard-period-ytd"].active')->assertNoJavaScriptErrors();
});

test('customise mode hides a widget, offers it back, and keeps the choice on reload', function () {
    $user = dashboardUser();
    $this->actingAs($user);

    visit('/dashboard')
        ->assertSee('Next 3 planned')
        ->click('[data-testid="dashboard-customise"]')
        ->assertPresent('[data-testid="widget-toolbar-upcoming-plans"]')
        ->click('[data-testid="widget-hide-upcoming-plans"]')
        ->assertDontSee('Next 3 planned')
        ->assertPresent('[data-testid="widget-show-upcoming-plans"]')
        ->assertNoJavaScriptErrors();

    expect($user->fresh()->preferences->dashboardLayout->isHidden(DashboardWidget::UpcomingPlans))->toBeTrue();

    visit('/dashboard')
        ->assertDontSee('Next 3 planned')
        ->click('[data-testid="dashboard-customise"]')
        ->click('[data-testid="widget-show-upcoming-plans"]')
        ->assertSee('Next 3 planned')
        ->assertNoJavaScriptErrors();

    expect($user->fresh()->preferences->dashboardLayout->isHidden(DashboardWidget::UpcomingPlans))->toBeFalse();
});

test('a collapsed widget shows only its bar and expands again', function () {
    $user = dashboardUser();
    $this->actingAs($user);

    visit('/dashboard')
        ->click('[data-testid="dashboard-customise"]')
        ->click('[data-testid="widget-collapse-recent-spending"]')
        ->assertDontSee('Spend last 7 days')
        ->click('[data-testid="widget-collapse-recent-spending"]')
        ->assertSee('Spend last 7 days')
        ->assertNoJavaScriptErrors();
});

test('the one column and width controls change the grid', function () {
    $user = dashboardUser();
    $this->actingAs($user);

    visit('/dashboard')
        ->assertPresent('[data-testid="dashboard-grid"].lg\:grid-cols-2')
        ->click('[data-testid="dashboard-customise"]')
        ->click('[data-testid="widget-width-pay-summary-half"]')
        ->click('[data-testid="dashboard-two-column"]')
        ->assertMissing('[data-testid="dashboard-grid"].lg\:grid-cols-2')
        ->assertNoJavaScriptErrors();

    $layout = $user->fresh()->preferences->dashboardLayout;

    expect($layout->twoColumn)->toBeFalse()
        ->and($layout->widths)->toBe(['pay-summary' => 'half']);
});

test('the grid is sortable by its handles only while customising', function () {
    $this->actingAs(dashboardUser());

    $sortable = "Object.getOwnPropertyNames(document.querySelector('[data-testid=dashboard-grid]')).some(name => /sortable/i.test(name))";

    visit('/dashboard')
        ->assertScript($sortable, false)
        ->click('[data-testid="dashboard-customise"]')
        ->assertScript($sortable, true)
        ->assertPresent('[data-testid="widget-handle-recent-spending"]')
        ->click('[data-testid="dashboard-customise"]')
        ->assertScript($sortable, false)
        ->assertNoJavaScriptErrors();
});

test('the net worth chart and balance sheet load lazily and the chart follows the period', function () {
    $user = dashboardUser();
    $account = $user->accounts()->firstOrFail();
    AccountDailyBalance::factory()->for($account)->create([
        'balance_date' => now()->subYears(2)->toDateString(),
        'start_balance' => 500000,
        'end_balance' => 500000,
    ]);
    $this->actingAs($user);

    visit('/dashboard')
        ->assertPresent('[data-test="balance-sheet"]')
        ->assertSeeIn('[data-test="net-worth"]', 'over Last 30 Days')
        ->click('[data-testid="dashboard-period-ytd"]')
        ->assertSeeIn('[data-test="net-worth"]', 'over Year to Date')
        ->assertSeeIn('[data-test="balance-sheet"]', 'Assets')
        ->assertNoJavaScriptErrors();
});
