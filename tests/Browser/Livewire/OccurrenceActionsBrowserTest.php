<?php

/** @noinspection JSUnresolvedReference */
/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\RecurrenceFrequency;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\User;

function browserPlan(User $user, Account $account, int $daysAhead = 3): PlannedTransaction
{
    return PlannedTransaction::factory()->for($user)->for($account)->create([
        'description' => 'Gym membership',
        'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => now()->addDays($daysAhead)->toDateString(),
        'until_date' => null,
        'amount' => 4500,
    ]);
}

test('the calendar day detail skips a stored occurrence', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $plan = browserPlan($user, $account);
    $occurrence = $plan->occurrences()->orderBy('original_date')->firstOrFail();
    $date = $occurrence->original_date->toDateString();

    $this->actingAs($user);

    $page = visit('/calendar');

    $page->click("[wire\\:click=\"selectDate('{$date}')\"]")
        ->assertPresent("[data-testid=\"occurrence-actions-{$occurrence->id}\"]")
        ->assertSeeIn("[data-testid=\"occurrence-actions-{$occurrence->id}\"] [data-testid=\"occurrence-state\"]", 'Due')
        ->screenshot(filename: 'occurrence-calendar-detail')
        ->click("[data-testid=\"occurrence-actions-{$occurrence->id}\"] [data-testid=\"occurrence-skip\"]")
        ->wait(2)
        ->assertNotPresent("[data-testid=\"occurrence-actions-{$occurrence->id}\"]")
        ->assertNoJavaScriptErrors();

    expect($occurrence->fresh()->status)->toBe(OccurrenceStatus::Skipped);
});

test('the calendar day detail snoozes a stored occurrence', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $plan = browserPlan($user, $account);
    $occurrence = $plan->occurrences()->orderBy('original_date')->firstOrFail();
    $date = $occurrence->original_date->toDateString();
    $moved = now()->addDays(5)->toDateString();

    $this->actingAs($user);

    $page = visit('/calendar');

    $page->click("[wire\\:click=\"selectDate('{$date}')\"]")
        ->fill("[data-testid=\"occurrence-actions-{$occurrence->id}\"] [data-testid=\"occurrence-snooze-date\"]", $moved)
        ->click("[data-testid=\"occurrence-actions-{$occurrence->id}\"] [data-testid=\"occurrence-snooze\"]")
        ->wait(2)
        ->assertNotPresent("[data-testid=\"occurrence-actions-{$occurrence->id}\"]")
        ->assertNoJavaScriptErrors();

    expect($occurrence->fresh()->snoozed_until->toDateString())->toBe($moved);
});

test('the rules page lists a plan occurrences and skips and reopens one', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $user->update(['primary_account_id' => $account->id]);
    $plan = browserPlan($user, $account);
    $occurrence = $plan->occurrences()->orderBy('original_date')->firstOrFail();

    $this->actingAs($user);

    $page = visit('/rules');

    $page->click("[data-testid=\"plan-occurrences-toggle-{$plan->id}\"]")
        ->assertPresent("[data-testid=\"occurrence-{$occurrence->id}\"]")
        ->assertSeeIn("[data-testid=\"occurrence-{$occurrence->id}\"] [data-testid=\"occurrence-state\"]", 'Due')
        ->screenshot(filename: 'occurrence-manager-list')
        ->click("[data-testid=\"occurrence-{$occurrence->id}\"] [data-testid=\"occurrence-skip\"]")
        ->wait(2)
        ->assertPresent("[data-testid=\"occurrence-{$occurrence->id}\"] [data-testid=\"occurrence-reopen\"]")
        ->screenshot(filename: 'occurrence-manager-skipped')
        ->click("[data-testid=\"occurrence-{$occurrence->id}\"] [data-testid=\"occurrence-reopen\"]")
        ->wait(2)
        ->assertPresent("[data-testid=\"occurrence-{$occurrence->id}\"] [data-testid=\"occurrence-skip\"]")
        ->assertNoJavaScriptErrors();

    expect($occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled);
});
