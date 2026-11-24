<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\DashboardWidget;
use App\Enums\PlannedTransactionStatus;
use App\Enums\PlannedTransactionType;
use App\Enums\PlanOrigin;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Livewire\Bills;
use App\Livewire\Dashboard\PaycheckPlan;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake();
    useAppTimezone('UTC');
    config(['budget.bills_page' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-03-12 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create(['balance' => 50000]);
    $this->user->update(['primary_account_id' => $this->account->id]);
});

function ppIncome(object $test, string $name, int $cents, string $start, RecurrenceFrequency $frequency = RecurrenceFrequency::Every2Weeks, ?User $user = null): PlannedTransaction
{
    $user ??= $test->user;
    $account = $user->is($test->user) ? $test->account : Account::factory()->for($user)->create();

    return PlannedTransaction::factory()->for($user)->for($account)->create([
        'description' => $name, 'amount' => $cents, 'direction' => TransactionDirection::Credit, 'type' => PlannedTransactionType::Income,
        'frequency' => $frequency, 'start_date' => $start, 'until_date' => null, 'origin' => PlanOrigin::Declared,
        'status' => PlannedTransactionStatus::Active, 'merchant_key' => null,
    ]);
}

function ppBill(object $test, string $name, int $cents, string $date): PlannedTransaction
{
    $plan = PlannedTransaction::factory()->for($test->user)->for($test->account)->create([
        'description' => $name, 'amount' => $cents, 'direction' => TransactionDirection::Debit, 'type' => PlannedTransactionType::Bill,
        'frequency' => RecurrenceFrequency::DontRepeat, 'start_date' => $date, 'until_date' => null, 'origin' => PlanOrigin::Declared,
        'status' => PlannedTransactionStatus::Active, 'merchant_key' => null,
    ]);

    App\Models\PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => $date, 'due_date' => $date]);

    return $plan;
}

it('prompts to add income when none is declared', function () {
    ppBill($this, 'Rent', 100000, '2026-03-20');

    Livewire::actingAs($this->user)->test(PaycheckPlan::class)->assertSeeHtml('data-testid="paycheck-prompt"')->assertSeeHtml('data-testid="paycheck-add-income"')->assertDontSeeHtml('data-testid="pay-period"');
});

it('shows three pay periods by default and at most six', function () {
    ppIncome($this, 'Salary', 160000, '2026-03-13');

    $page = Livewire::actingAs($this->user)->test(PaycheckPlan::class);

    expect(mb_substr_count($page->html(), 'data-testid="pay-period"'))->toBe(3);

    $page->call('showPeriods', 10)->assertSet('periods', 6);

    expect(mb_substr_count($page->html(), 'data-testid="pay-period"'))->toBe(6);

    $page->assertDontSeeHtml('data-testid="paycheck-show-more"')->call('showPeriods', 0)->assertSet('periods', 1);
});

it('offers more periods from the widget', function () {
    ppIncome($this, 'Salary', 160000, '2026-03-13');

    $page = Livewire::actingAs($this->user)->test(PaycheckPlan::class)->assertSeeHtml('data-testid="paycheck-show-more"')->assertSeeHtml('wire:click="showPeriods(6)"')->call('showPeriods', 6);

    expect(mb_substr_count($page->html(), 'data-testid="pay-period"'))->toBe(6);
});

it('shows a shortfall instead of a negative safe to spend', function () {
    ppIncome($this, 'Salary', 100000, '2026-03-13');
    ppBill($this, 'Huge', 450000, '2026-03-30');

    $html = Livewire::actingAs($this->user)->test(PaycheckPlan::class)->assertSeeHtml('data-testid="period-shortfall"')->html();

    expect($html)->not->toContain('-$')->and(mb_substr_count($html, 'data-testid="period-shortfall"'))->toBe(1);
});

it('shows the bridge window and the paycheck figures', function () {
    ppIncome($this, 'Salary', 160000, '2026-03-20');
    ppBill($this, 'Phone', 6000, '2026-03-15');

    Livewire::actingAs($this->user)->test(PaycheckPlan::class)->assertSeeHtml('data-testid="pay-bridge"')->assertSee('$1,600.00')->assertSee('Salary')->assertSee('Phone')->assertSee('$60.00');
});

it('never shows another users paycheck plan', function () {
    $other = User::factory()->create();
    ppIncome($this, 'Their salary', 900000, '2026-03-13', RecurrenceFrequency::Every2Weeks, $other);

    Livewire::actingAs($this->user)->test(PaycheckPlan::class)->assertSeeHtml('data-testid="paycheck-prompt"')->assertDontSee('Their salary')->assertDontSee('$9,000.00');
});

it('lists income that was left out with the reason', function () {
    $plan = ppIncome($this, 'Old pay', 160000, '2026-03-13');
    $plan->update(['status' => PlannedTransactionStatus::Inactive]);

    Livewire::actingAs($this->user)->test(PaycheckPlan::class)->assertSee('Old pay')->assertSee('Paused');
});

it('refreshes when a transaction is saved or a plan is declared', function () {
    $page = Livewire::actingAs($this->user)->test(PaycheckPlan::class)->assertSeeHtml('data-testid="paycheck-prompt"');

    ppIncome($this, 'Salary', 160000, '2026-03-13');

    $page->dispatch('plan-declared')->assertDontSeeHtml('data-testid="paycheck-prompt"')->assertSeeHtml('data-testid="pay-period"');

    $page->set('periods', 3)->dispatch('transaction-saved')->assertSeeHtml('data-testid="pay-period"');
});

it('registers the widget for the dashboard and hides it while recurring is off', function () {
    expect(DashboardWidget::PaycheckPlan->component())->toBe('dashboard.paycheck-plan')->and(DashboardWidget::PaycheckPlan->lazy())->toBeTrue()
        ->and(DashboardWidget::PaycheckPlan->availableTo($this->user))->toBeTrue();

    $this->user->updatePreferences(['recurringEnabled' => false]);

    expect(DashboardWidget::PaycheckPlan->availableTo($this->user->fresh()))->toBeFalse();
});

it('shows pay period markers only when income splits the month', function () {
    ppBill($this, 'Gym', 4000, '2026-03-14');
    ppBill($this, 'Water', 9000, '2026-03-26');
    ppIncome($this, 'Monthly pay', 300000, '2026-03-01', RecurrenceFrequency::EveryMonth);

    Livewire::actingAs($this->user)->test(Bills::class)->assertDontSeeHtml('data-testid="pay-marker"')->assertSee('Gym');

    PlannedTransaction::query()->where('description', 'Monthly pay')->delete();
    ppIncome($this, 'Fortnight pay', 150000, '2026-03-06');

    $page = Livewire::actingAs($this->user)->test(Bills::class);
    $html = $page->html();

    expect(mb_substr_count($html, 'data-testid="pay-marker"'))->toBeGreaterThanOrEqual(2);

    $page->assertSee('Payday')->assertSee('Fortnight pay');
});

it('sums the obligations of each pay period in the marker', function () {
    ppBill($this, 'Gym', 4000, '2026-03-14');
    ppBill($this, 'Water', 9000, '2026-03-26');
    ppIncome($this, 'Fortnight pay', 150000, '2026-03-06');

    $markers = Livewire::actingAs($this->user)->test(Bills::class)->instance()->payMarkers;

    expect(collect($markers)->sum('obligation'))->toBe(13000)->and(collect($markers)->firstWhere('startsOn', CarbonImmutable::parse('2026-03-20'))['obligation'])->toBe(9000);
});

it('splits the month into two periods when pay lands once mid month', function () {
    ppBill($this, 'Gym', 4000, '2026-03-14');
    ppIncome($this, 'Mid pay', 300000, '2026-03-15', RecurrenceFrequency::EveryMonth);

    $markers = Livewire::actingAs($this->user)->test(Bills::class)->instance()->payMarkers;

    expect($markers)->toHaveCount(2)->and($markers[0]['sources'])->toBe([]);
});

it('never marks another users income on the bills page', function () {
    $other = User::factory()->create();
    ppBill($this, 'Gym', 4000, '2026-03-14');
    ppIncome($this, 'Their pay', 150000, '2026-03-06', RecurrenceFrequency::Every2Weeks, $other);

    Livewire::actingAs($this->user)->test(Bills::class)->assertDontSeeHtml('data-testid="pay-marker"');
});

it('does not offer a hidden paycheck plan while recurring is off', function () {
    $layout = $this->user->preferences->dashboardLayout->hide(DashboardWidget::PaycheckPlan);
    $this->user->updatePreferences(['dashboardLayout' => $layout->toArray()]);

    expect(array_map(static fn (DashboardWidget $widget): string => $widget->value, Livewire::actingAs($this->user->fresh())->test(App\Livewire\Dashboard::class)->instance()->hiddenOffers()))->toContain('paycheck-plan');

    $this->user->updatePreferences(['recurringEnabled' => false]);

    expect(array_map(static fn (DashboardWidget $widget): string => $widget->value, Livewire::actingAs($this->user->fresh())->test(App\Livewire\Dashboard::class)->instance()->hiddenOffers()))->not->toContain('paycheck-plan');
});

it('refreshes the dashboard figures when a plan is declared', function () {
    $page = Livewire::actingAs($this->user)->test(App\Livewire\Dashboard::class)->assertDontSee('Zebra insurance');

    ppBill($this, 'Zebra insurance', 4000, '2026-03-14');

    $page->dispatch('plan-declared')->assertSee('Zebra insurance');
});
