<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\OccurrenceStatus;
use App\Enums\RecurrenceFrequency;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Livewire\CalendarView;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    useAppTimezone('Australia/Brisbane');
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'Australia/Brisbane'));
    $this->user = User::factory()->create();
    $this->plan = PlannedTransaction::factory()->for($this->user)->for(Account::factory()->for($this->user))->create([
        'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2026-01-15',
        'until_date' => null,
        'amount' => 10000,
        'description' => 'Gym membership',
    ]);
    $this->occurrence = $this->plan->occurrences()->whereDate('original_date', '2026-03-15')->sole();
});

function calendarOn(object $test, string $date = '2026-03-15')
{
    return Livewire::actingAs($test->user)->test(CalendarView::class)->set('selectedDate', $date);
}

it('shows the state and actions of a stored occurrence in the day detail', function () {
    calendarOn($this)
        ->assertSee('Gym membership')
        ->assertSeeHtmlInOrder(['data-testid="occurrence-actions-'.$this->occurrence->id.'"', 'Upcoming']);
});

it('skips an occurrence from the day detail and takes it off the day', function () {
    calendarOn($this)->call('skipOccurrence', $this->occurrence->id)->assertHasNoErrors();

    expect($this->occurrence->fresh()->status)->toBe(OccurrenceStatus::Skipped);

    calendarOn($this)->assertDontSeeHtml('data-testid="occurrence-actions-'.$this->occurrence->id.'"');
});

it('audits skipping an occurrence', function () {
    calendarOn($this)->call('skipOccurrence', $this->occurrence->id);

    $event = AuditEvent::query()->where('action', 'livewire.calendar-view.skipOccurrence')->sole();

    expect($event->user_id)->toBe($this->user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success);
});

it('snoozes an occurrence from the day detail and moves it to the new day', function () {
    $component = calendarOn($this)
        ->set("snoozeDates.{$this->occurrence->id}", '2026-03-24')
        ->call('snoozeOccurrence', $this->occurrence->id)
        ->assertHasNoErrors();

    expect($this->occurrence->fresh()->snoozed_until->toDateString())->toBe('2026-03-24');

    $component->set('selectedDate', '2026-03-24')->assertSeeHtml('data-testid="occurrence-actions-'.$this->occurrence->id.'"');
    calendarOn($this)->assertDontSeeHtml('data-testid="occurrence-actions-'.$this->occurrence->id.'"');
});

it('shows a message for a snooze date that cannot be read and changes nothing', function () {
    calendarOn($this)
        ->set("snoozeDates.{$this->occurrence->id}", 'garbage')
        ->call('snoozeOccurrence', $this->occurrence->id)
        ->assertHasErrors(['occurrence'])
        ->assertSee('That date could not be read.');

    expect($this->occurrence->fresh()->snoozed_until)->toBeNull();
    expect(AuditEvent::query()->where('action', 'livewire.calendar-view.snoozeOccurrence')->sole()->outcome)->toBe(AuditOutcome::Failure);
});

it('refuses to act on another users occurrence from the calendar', function (string $method) {
    $other = User::factory()->create();

    Livewire::actingAs($other)->test(CalendarView::class)->call($method, $this->occurrence->id)->assertNotFound();

    expect($this->occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled)
        ->and($this->occurrence->fresh()->snoozed_until)->toBeNull();
})->with(['skipOccurrence', 'snoozeOccurrence']);

it('shows an overdue stored occurrence from the current cycle on its past day', function () {
    $this->plan->update(['start_date' => '2026-01-02']);
    $overdue = $this->plan->occurrences()->whereDate('original_date', '2026-03-02')->first()
        ?? PlannedOccurrence::factory()->for($this->plan, 'plannedTransaction')->create(['original_date' => '2026-03-02', 'due_date' => '2026-03-02']);

    calendarOn($this, '2026-03-02')
        ->assertSeeHtmlInOrder(['data-testid="occurrence-actions-'.$overdue->id.'"', 'Overdue']);
});

it('keeps a skipped occurrence off the calendar after the generator runs again', function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    calendarOn($this)->call('skipOccurrence', $this->occurrence->id);

    app()->call(new GeneratePlannedOccurrencesJob($this->user)->handle(...));
    app()->call(new GeneratePlannedOccurrencesJob($this->user, true)->handle(...));

    calendarOn($this)->assertDontSeeHtml('data-testid="occurrence-actions-'.$this->occurrence->id.'"');
    expect($this->plan->occurrences()->whereDate('original_date', '2026-03-15')->count())->toBe(1);
});
