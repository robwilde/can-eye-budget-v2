<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\User;

test('the recurring settings page switches recurring off and pauses a plan', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Everyday']);
    $user->update(['primary_account_id' => $account->id]);
    $plan = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create(['description' => 'Gym']);

    $this->actingAs($user);

    visit('/settings/recurring')
        ->assertSee('Gym')
        ->assertSee('Recurring bills and income')
        ->click("[data-testid=\"plan-pause-{$plan->id}\"]")
        ->wait(1)
        ->assertSee('Inactive')
        ->click('[data-testid="recurring-switch"]')
        ->wait(1)
        ->screenshot(filename: 'recurring-settings')
        ->assertNoJavaScriptErrors();

    expect($user->fresh()->preferences->recurringEnabled)->toBeFalse()->and($plan->fresh()->isActive())->toBeFalse();
});
