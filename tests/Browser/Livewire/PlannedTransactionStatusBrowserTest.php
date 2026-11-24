<?php

/** @noinspection JSUnresolvedReference */
/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PlannedTransactionStatus;
use App\Enums\PlanOrigin;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\User;

test('the rules page pauses, resumes, tombstones a detected plan and removes a declared one', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Everyday']);
    $user->update(['primary_account_id' => $account->id]);
    $declared = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create(['description' => 'Declared rent', 'origin' => PlanOrigin::Declared]);
    $detected = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create(['description' => 'Detected stream', 'origin' => PlanOrigin::Detected]);

    $this->actingAs($user);

    visit('/rules')
        ->assertSee('Declared rent')
        ->click("[data-testid=\"plan-toggle-{$declared->id}\"]")
        ->wait(1)
        ->assertSee('Inactive')
        ->click("[data-testid=\"plan-toggle-{$declared->id}\"]")
        ->wait(1)
        ->click("[data-testid=\"plan-delete-{$detected->id}\"]")
        ->wait(1)
        ->assertSee('kept out of sight')
        ->screenshot(filename: 'plan-status-delete')
        ->click('[data-testid="plan-delete-confirm"]')
        ->wait(2)
        ->assertDontSee('Detected stream')
        ->click("[data-testid=\"plan-delete-{$declared->id}\"]")
        ->wait(1)
        ->click('[data-testid="plan-delete-confirm"]')
        ->wait(2)
        ->assertDontSee('Declared rent')
        ->assertNoJavaScriptErrors();

    expect($declared->fresh())->toBeNull()
        ->and($detected->fresh()->status)->toBe(PlannedTransactionStatus::Ended);
});
