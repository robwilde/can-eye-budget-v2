<?php

/** @noinspection JSUnresolvedReference */
/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RecurrenceFrequency;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\User;

test('the rules page picker saves an every N months cadence with the last day and pins it', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Everyday']);
    $user->update(['primary_account_id' => $account->id]);
    $plan = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create(['description' => 'Council rates', 'amount' => 30000]);

    $this->actingAs($user);

    visit('/rules')
        ->click("button[wire\\:click=\"openEdit({$plan->id})\"]")
        ->assertPresent('[data-testid="plan-cadence-fields"]')
        ->assertMissing('[data-testid="plan-cadence-interval"]')
        ->select('[data-testid="plan-frequency"]', 'every-n-months')
        ->wait(1)
        ->assertPresent('[data-testid="plan-cadence-interval"]')
        ->fill('[data-testid="plan-cadence-interval"]', '3')
        ->fill('[data-testid="plan-cadence-days"]', 'last')
        ->select('[data-testid="plan-cadence-weekend"]', 'before')
        ->screenshot(filename: 'cadence-picker-manager')
        ->click('Update')
        ->wait(2)
        ->assertSee('Every 3 months')
        ->assertNoJavaScriptErrors();

    $fresh = $plan->fresh();

    expect($fresh->frequency)->toBe(RecurrenceFrequency::EveryNMonths)
        ->and($fresh->recurrence_interval)->toBe(3)
        ->and($fresh->days_of_month)->toBe(['last'])
        ->and($fresh->schedule_pinned)->toBeTrue();
});
