<?php

/** @noinspection JSUnresolvedReference */
/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\User;

$openModal = <<<'JS'
    Livewire.dispatch('open-transaction-modal', { date: '2026-04-19' })
JS;

test('the transaction modal offers the plan toggle while recurring is on', function () use ($openModal) {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    $this->actingAs($user);

    $page = visit('/calendar');

    $page->script($openModal);

    $page->assertPresent('[data-testid="transaction-type"]')
        ->assertPresent('[data-testid="mode-plan"]')
        ->assertNoJavaScriptErrors();
});

test('the transaction modal hides the plan toggle while recurring is switched off', function () use ($openModal) {
    $user = User::factory()->create();
    $user->updatePreferences(['recurringEnabled' => false]);
    Account::factory()->for($user)->create();

    $this->actingAs($user);

    $page = visit('/calendar');

    $page->script($openModal);

    $page->assertPresent('[data-testid="transaction-type"]')
        ->assertNotPresent('[data-testid="mode-plan"]')
        ->screenshot(filename: 'recurring-off-modal-no-plan-toggle')
        ->assertNoJavaScriptErrors();
});

test('the plan manager shows a notice and disables its plan buttons while recurring is switched off', function () {
    $user = User::factory()->create();
    $user->updatePreferences(['recurringEnabled' => false]);
    $account = Account::factory()->for($user)->create(['name' => 'Everyday']);
    $user->update(['primary_account_id' => $account->id]);
    $plan = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create(['description' => 'Netflix', 'amount' => 2299]);

    $this->actingAs($user);

    $page = visit('/rules');

    $page->assertSee('Netflix')
        ->assertPresent('[data-testid="recurring-off-notice"]')
        ->assertSee('Recurring is switched off')
        ->assertPresent("button[wire\\:click=\"openEdit({$plan->id})\"][disabled]")
        ->assertPresent("[data-testid=\"plan-toggle-{$plan->id}\"][disabled]")
        ->assertPresent("[data-testid=\"plan-delete-{$plan->id}\"][disabled]")
        ->assertPresent('[data-testid="add-bill"][disabled]')
        ->screenshot(filename: 'recurring-off-plan-manager')
        ->assertNoJavaScriptErrors();

    expect($plan->fresh()->isActive())->toBeTrue();
});

test('the plan manager has no notice and live buttons while recurring is on', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Everyday']);
    $user->update(['primary_account_id' => $account->id]);
    $plan = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create(['description' => 'Netflix', 'amount' => 2299]);

    $this->actingAs($user);

    $page = visit('/rules');

    $page->assertSee('Netflix')
        ->assertNotPresent('[data-testid="recurring-off-notice"]')
        ->assertNotPresent("button[wire\\:click=\"openEdit({$plan->id})\"][disabled]")
        ->assertNoJavaScriptErrors();
});
