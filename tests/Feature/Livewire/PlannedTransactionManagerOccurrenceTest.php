<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\OccurrenceStatus;
use App\Enums\PlannedTransactionStatus;
use App\Enums\RecurrenceFrequency;
use App\Livewire\PlannedTransactionManager;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\PlannedTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    useAppTimezone('Australia/Brisbane');
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'Australia/Brisbane'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->user->update(['primary_account_id' => $this->account->id]);
    $this->plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create([
        'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2026-01-15',
        'until_date' => null,
        'amount' => 10000,
    ]);
    $this->occurrence = $this->plan->occurrences()->whereDate('original_date', '2026-03-15')->sole();
});

function managerOpen(object $test)
{
    return Livewire::actingAs($test->user)->test(PlannedTransactionManager::class)->set('occurrencesPlanId', $test->plan->id);
}

it('lists the stored occurrences of a plan from the current cycle on with their states', function () {
    managerOpen($this)
        ->assertSeeHtmlInOrder(['data-testid="occurrence-'.$this->occurrence->id.'"', '2026-03-15', 'Upcoming', 'data-testid="occurrence-'.$this->plan->occurrences()->orderByDesc('original_date')->value('id').'"']);
});

it('does not list occurrences until a plan is opened', function () {
    Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)
        ->assertDontSeeHtml('data-testid="plan-occurrences"');
});

it('audits skipping an occurrence', function () {
    managerOpen($this)->call('skipOccurrence', $this->occurrence->id)->assertHasNoErrors();

    $event = AuditEvent::query()->where('action', 'livewire.planned-transaction-manager.skipOccurrence')->sole();

    expect($event->user_id)->toBe($this->user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success)
        ->and($this->occurrence->fresh()->status)->toBe(OccurrenceStatus::Skipped);
});

it('reopens a skipped occurrence and keeps its frozen amount', function () {
    $this->occurrence->update(['expected_amount' => 9500]);

    managerOpen($this)->call('skipOccurrence', $this->occurrence->id)->assertSee('Skipped')->call('reopenOccurrence', $this->occurrence->id)->assertHasNoErrors();

    $fresh = $this->occurrence->fresh();

    expect($fresh->status)->toBe(OccurrenceStatus::Scheduled)
        ->and($fresh->expected_amount)->toBe(9500);
});

it('marks an occurrence missed only on request', function () {
    managerOpen($this)->call('markOccurrenceMissed', $this->occurrence->id)->assertSeeText('Missed');

    expect($this->occurrence->fresh()->status)->toBe(OccurrenceStatus::Missed);
});

it('snoozes an occurrence and shows it as upcoming', function () {
    $this->plan->update(['start_date' => '2026-01-02']);
    $next = $this->plan->occurrences()->whereDate('original_date', '2026-04-02')->sole();
    $overdue = $this->plan->occurrences()->whereDate('original_date', '2026-03-02')->sole();

    managerOpen($this)->assertSeeHtmlInOrder(['data-testid="occurrence-'.$overdue->id.'"', 'Overdue', 'data-testid="occurrence-'.$next->id.'"'])
        ->set("snoozeDates.{$overdue->id}", '2026-03-20')
        ->call('snoozeOccurrence', $overdue->id)
        ->assertHasNoErrors()
        ->assertSeeHtmlInOrder(['data-testid="occurrence-'.$overdue->id.'"', '2026-03-20', 'Upcoming', 'data-testid="occurrence-'.$next->id.'"']);

    expect($overdue->fresh()->snoozed_until->toDateString())->toBe('2026-03-20');
});

it('shows a message for a snooze date that cannot be read', function () {
    managerOpen($this)
        ->set("snoozeDates.{$this->occurrence->id}", '2026-02-30')
        ->call('snoozeOccurrence', $this->occurrence->id)
        ->assertSee('That date could not be read.');

    expect($this->occurrence->fresh()->snoozed_until)->toBeNull();
});

it('sets and clears the amount of one occurrence', function () {
    managerOpen($this)
        ->set("occurrenceAmounts.{$this->occurrence->id}", '88.50')
        ->call('setOccurrenceAmount', $this->occurrence->id)
        ->assertHasNoErrors();

    expect($this->occurrence->fresh()->expected_amount)->toBe(8850);

    managerOpen($this)
        ->set("occurrenceAmounts.{$this->occurrence->id}", '')
        ->call('setOccurrenceAmount', $this->occurrence->id);

    expect($this->occurrence->fresh()->expected_amount)->toBeNull();
});

it('refuses an amount that is not a positive figure with at most two decimals', function (string $amount) {
    managerOpen($this)
        ->set("occurrenceAmounts.{$this->occurrence->id}", $amount)
        ->call('setOccurrenceAmount', $this->occurrence->id)
        ->assertHasErrors(['occurrence']);

    expect($this->occurrence->fresh()->expected_amount)->toBeNull();
})->with(['10.123', '-5', 'abc', '1,000']);

it('refuses a zero amount', function () {
    managerOpen($this)
        ->set("occurrenceAmounts.{$this->occurrence->id}", '0')
        ->call('setOccurrenceAmount', $this->occurrence->id)
        ->assertHasErrors(['occurrence']);

    expect($this->occurrence->fresh()->expected_amount)->toBeNull();
});

it('refuses to act on another users occurrence', function (string $method) {
    $other = User::factory()->create();
    $before = $this->occurrence->fresh()->getAttributes();

    Livewire::actingAs($other)->test(PlannedTransactionManager::class)->call($method, $this->occurrence->id)->assertNotFound();

    expect($this->occurrence->fresh()->getAttributes())->toBe($before);
})->with(['skipOccurrence', 'markOccurrenceMissed', 'reopenOccurrence', 'snoozeOccurrence', 'setOccurrenceAmount']);

it('lists only the occurrences of the opened plan and the user', function () {
    $other = User::factory()->create();
    $theirPlan = PlannedTransaction::factory()->for($other)->for(Account::factory()->for($other))->create(['frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2026-01-15']);
    $theirOccurrence = $theirPlan->occurrences()->first();

    $component = Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)->set('occurrencesPlanId', $theirPlan->id);

    $component->assertDontSeeHtml('data-testid="occurrence-'.$theirOccurrence->id.'"')
        ->assertViewHas('occurrences', fn ($occurrences): bool => $occurrences->isEmpty());
});

it('shows open occurrences of a paused plan as paused', function () {
    $this->plan->update(['status' => PlannedTransactionStatus::Inactive]);

    Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)->set('occurrencesPlanId', $this->plan->id)->assertSee('Paused');
});
