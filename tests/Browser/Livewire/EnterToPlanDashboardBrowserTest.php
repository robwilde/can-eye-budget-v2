<?php

/** @noinspection JSUnresolvedReference */
/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;

/**
 * Reproduction for #257: converting an entered transaction to a plan from the
 * dashboard. The conversion always worked at the data layer, but the dashboard
 * pay-cycle calendar never refreshed (it lacked the `transaction-saved`
 * listener the dedicated /calendar page has), so the converted day kept showing
 * the stale "out" pip until a full page reload — the conversion appeared to do
 * nothing. This drives the real browser flow and asserts the live refresh.
 */
test('enter to plan conversion live-refreshes the dashboard pay-cycle calendar', function () {
    $user = User::factory()->withPayCycle()->create();
    $account = Account::factory()->for($user)->create();

    $transaction = Transaction::factory()->for($user)->for($account)->manual()->create([
        'amount' => 20000,
        'direction' => TransactionDirection::Debit,
        'description' => 'Direct Debit Spaceship',
        'post_date' => now()->toDateString(),
        'category_id' => null,
    ]);

    $this->actingAs($user);

    $page = visit('/dashboard');

    // The entered expense renders as an "out" pip in today's cell, and there is
    // no planned pip yet.
    $page->assertPresent('.cyc-pip.out')
        ->assertNotPresent('.cyc-pip.plan');

    // Open the transaction in the globally-mounted modal exactly as a pip click
    // does (tx-row dispatches `edit-transaction`), switch Enter -> Plan, save.
    $page->script("Livewire.dispatch('edit-transaction', { id: {$transaction->id} })");

    $page->assertPresent('.type-toggle')
        ->click('Plan')
        ->assertSee('Frequency')
        ->click('Convert to planned expense');

    // Without the fix the grid keeps the stale "out" pip; with it the day now
    // surfaces the converted plan as a "plan" pip — no reload performed.
    $page->assertPresent('.cyc-pip.plan')
        ->assertNotPresent('.cyc-pip.out');
});
