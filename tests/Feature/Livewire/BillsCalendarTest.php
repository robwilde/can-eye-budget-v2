<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\PlannedTransactionStatus;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Livewire\Bills;
use App\Livewire\TransactionList;
use App\Livewire\UpcomingRecurring;
use App\Models\Account;
use App\Models\PlannedOccurrence;
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    useAppTimezone('UTC');
    config(['budget.bills_page' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->user->update(['primary_account_id' => $this->account->id]);
});

function calPlan(object $test, string $name, int $cents, array $attributes = [], ?User $user = null, ?Account $account = null): PlannedTransaction
{
    return PlannedTransaction::factory()->for($user ?? $test->user)->for($account ?? $test->account)->create([
        'description' => $name, 'amount' => $cents, 'direction' => TransactionDirection::Debit, 'frequency' => RecurrenceFrequency::DontRepeat,
        'start_date' => '2030-01-01', 'until_date' => null, 'merchant_key' => null, ...$attributes,
    ]);
}

function calOccurrence(PlannedTransaction $plan, string $date, array $attributes = [], int $paid = 0): PlannedOccurrence
{
    $occurrence = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => $date, 'due_date' => $date, ...$attributes]);

    if ($paid > 0) {
        PlannedPayment::factory()->create(['planned_occurrence_id' => $occurrence->id, 'transaction_id' => null, 'amount' => $paid, 'paid_on' => '2026-03-10']);
    }

    return $occurrence;
}

function calendarPage(object $test, string $month = '')
{
    return Livewire::actingAs($test->user)->test(Bills::class)->set('tab', 'calendar')->set('month', $month);
}

it('totals bills for the browsed month', function () {
    calOccurrence(calPlan($this, 'Rent', 100000), '2026-03-05', ['status' => OccurrenceStatus::Paid, 'closed_at' => now()], 100000);
    calOccurrence(calPlan($this, 'Gym', 3000), '2026-03-20');
    calOccurrence(calPlan($this, 'Water', 8000), '2026-03-28');

    $page = calendarPage($this, '2026-03');

    expect($page->instance()->calendar->count)->toBe(3)->and($page->instance()->calendar->expected)->toBe(111000)->and($page->instance()->calendar->paid)->toBe(100000);

    $page->assertSee('3 bills')->assertSee('expected $1,110.00')->assertSee('paid $1,000.00')->assertSeeHtml('data-testid="calendar-day-2026-03-20"');
});

it('lays the month out Monday first with a chip on its due day', function () {
    calOccurrence(calPlan($this, 'Gym', 3000), '2026-03-20');

    $calendar = calendarPage($this, '2026-03')->instance()->calendar;

    expect($calendar->days[0]['date']->dayOfWeekIso)->toBe(1)->and(count($calendar->days) % 7)->toBe(0)
        ->and(collect($calendar->days)->first(fn (array $day): bool => $day['date']->format('Y-m-d') === '2026-03-20')['bills'][0]->name)->toBe('Gym');

    calendarPage($this, '2026-03')->assertSeeHtml('data-testid="calendar-chip"');
});

it('puts a snoozed bill on its snoozed day', function () {
    calOccurrence(calPlan($this, 'Gym', 3000), '2026-03-20', ['snoozed_until' => '2026-03-25']);

    $calendar = calendarPage($this, '2026-03')->instance()->calendar;

    expect(collect($calendar->days)->first(fn (array $day): bool => $day['date']->format('Y-m-d') === '2026-03-25')['bills'])->toHaveCount(1)
        ->and(collect($calendar->days)->first(fn (array $day): bool => $day['date']->format('Y-m-d') === '2026-03-20')['bills'])->toHaveCount(0);
});

it('falls back to the current month for an invalid month', function (string $month) {
    expect(calendarPage($this, $month)->instance()->calendar->month)->toBe('2026-03');
})->with(['month 13' => '2026-13', 'garbage' => 'abc', 'empty' => '', 'day' => '2026-03-01', 'zero month' => '2026-00']);

it('generates occurrences on demand when browsing ahead', function () {
    calPlan($this, 'Gym', 3000, ['frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2026-01-10']);
    PlannedOccurrence::query()->delete();

    $page = calendarPage($this, '2027-03');

    expect($page->instance()->calendar->month)->toBe('2027-03')->and($page->instance()->calendar->count)->toBe(1)->and(PlannedOccurrence::query()->whereDate('due_date', '2027-03-10')->count())->toBe(1);

    $page->set('month', '2027-05');

    expect($page->instance()->calendar->month)->toBe('2027-04')->and($page->instance()->calendar->canGoForward)->toBeFalse()
        ->and(PlannedOccurrence::query()->whereDate('due_date', '>=', '2027-05-01')->count())->toBe(0);
});

it('navigates by month and back to today', function () {
    $page = calendarPage($this)->call('nextMonth')->assertSet('month', '2026-04')->call('nextMonth')->assertSet('month', '2026-05')->call('previousMonth')->assertSet('month', '2026-04')->call('goToToday')->assertSet('month', '');

    expect($page->instance()->calendar->isCurrent)->toBeTrue();
});

it('stops browsing back after twenty four months', function () {
    expect(calendarPage($this, '2020-01')->instance()->calendar->month)->toBe('2024-03');
});

it('leaves paused plans and other users bills off the calendar', function () {
    $other = User::factory()->create();
    calOccurrence(calPlan($this, 'Paused', 1000, ['status' => PlannedTransactionStatus::Inactive]), '2026-03-20');
    calOccurrence(calPlan($this, 'Theirs', 1000, [], $other, Account::factory()->for($other)->create()), '2026-03-21');
    calOccurrence(calPlan($this, 'Mine', 1000), '2026-03-22');

    calendarPage($this, '2026-03')->assertSee('Mine')->assertDontSee('Paused')->assertDontSee('Theirs');
});

it('shows projected recurring items for the next ten days', function () {
    $plan = calPlan($this, 'Electricity', 5000, ['frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2025-12-01']);
    calOccurrence($plan, '2026-01-20', ['status' => OccurrenceStatus::Paid, 'closed_at' => now()], 4000);
    calOccurrence($plan, '2026-02-20', ['status' => OccurrenceStatus::Paid, 'closed_at' => now()], 6000);
    calOccurrence($plan, '2026-03-20');
    $steady = calPlan($this, 'Phone', 3000);
    calOccurrence($steady, '2026-03-18');
    calOccurrence(calPlan($this, 'Far away', 1000), '2026-04-20');

    Livewire::actingAs($this->user)->test(UpcomingRecurring::class)->assertSee('Electricity')->assertSee('$40.00–$60.00')->assertSee('Phone')->assertSee('$30.00')->assertDontSee('Far away');
});

it('shows the items above the transactions list without the bills flag', function () {
    config(['budget.bills_page' => false]);
    calOccurrence(calPlan($this, 'Phone', 3000), '2026-03-18');

    $this->actingAs($this->user)->get(route('transactions'))->assertOk()->assertSee('data-testid="upcoming-recurring"', false)->assertSee('Phone');
});

it('never projects another users recurring items', function () {
    $other = User::factory()->create();
    calOccurrence(calPlan($this, 'Theirs', 3000, [], $other, Account::factory()->for($other)->create()), '2026-03-16');

    Livewire::actingAs($this->user)->test(UpcomingRecurring::class)->assertDontSee('Theirs');
});

it('projects nothing while recurring is switched off and leaves transfers and paused plans out', function () {
    $savings = Account::factory()->for($this->user)->create();
    calOccurrence(calPlan($this, 'Phone', 3000), '2026-03-18');
    calOccurrence(calPlan($this, 'Sweep', 5000, ['transfer_to_account_id' => $savings->id]), '2026-03-18');
    calOccurrence(calPlan($this, 'Paused', 2000, ['status' => PlannedTransactionStatus::Inactive]), '2026-03-18');

    Livewire::actingAs($this->user)->test(UpcomingRecurring::class)->assertSee('Phone')->assertDontSee('Sweep')->assertDontSee('Paused');

    $this->user->updatePreferences(['recurringEnabled' => false]);

    Livewire::actingAs($this->user->fresh())->test(UpcomingRecurring::class)->assertDontSee('Phone');
});

it('lists income among the projected items', function () {
    calOccurrence(calPlan($this, 'Salary', 200000, ['direction' => TransactionDirection::Credit]), '2026-03-19');

    Livewire::actingAs($this->user)->test(UpcomingRecurring::class)->assertSee('Salary')->assertSee('Income');
});

it('mounts inside the transaction list component', function () {
    calOccurrence(calPlan($this, 'Phone', 3000), '2026-03-18');

    Livewire::actingAs($this->user)->test(TransactionList::class)->assertSeeHtml('data-testid="upcoming-recurring"');
});

it('projects a card repayment bill but not a transfer to an ordinary account', function () {
    $card = Account::factory()->for($this->user)->creditCard()->create();
    calOccurrence(calPlan($this, 'Card repayment', 20000, ['transfer_to_account_id' => $card->id]), '2026-03-18');

    Livewire::actingAs($this->user)->test(UpcomingRecurring::class)->assertSee('Card repayment');
});

it('shows the amount set on an occurrence instead of the history range', function () {
    $plan = calPlan($this, 'Electricity', 5000, ['frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2025-12-01']);
    calOccurrence($plan, '2026-01-20', ['status' => OccurrenceStatus::Paid, 'closed_at' => now()], 4000);
    calOccurrence($plan, '2026-02-20', ['status' => OccurrenceStatus::Paid, 'closed_at' => now()], 6000);
    calOccurrence($plan, '2026-03-20', ['expected_amount' => 18000]);

    Livewire::actingAs($this->user)->test(UpcomingRecurring::class)->assertSee('$180.00')->assertDontSee('$40.00–$60.00');
});

it('refreshes the projected items when a transaction is saved or a payment changes', function () {
    $plan = calPlan($this, 'Phone', 3000);
    $occurrence = calOccurrence($plan, '2026-03-18');
    $component = Livewire::actingAs($this->user)->test(UpcomingRecurring::class)->assertSee('Phone');

    $occurrence->update(['status' => OccurrenceStatus::Paid, 'closed_at' => now()]);

    $component->dispatch('transaction-saved')->assertDontSee('Phone');
});

it('does not generate occurrences for months the nightly job already covers', function () {
    calPlan($this, 'Gym', 3000, ['frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2026-01-10']);
    PlannedOccurrence::query()->delete();

    calendarPage($this, '2026-04');

    expect(PlannedOccurrence::query()->count())->toBe(0);

    calendarPage($this, '2026-12');

    expect(PlannedOccurrence::query()->count())->toBeGreaterThan(0);
});
