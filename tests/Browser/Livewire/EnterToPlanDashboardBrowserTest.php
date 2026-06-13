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
 * dashboard never refreshed the pay-cycle calendar (it lacked the
 * `transaction-saved` listener the dedicated /calendar page has), so the
 * converted day kept stale pips until a full page reload.
 *
 * Since the reconciliation-lifecycle change a converted posting is linked to
 * the new plan, so its day keeps the (now reconciled) "out" pip instead of
 * flipping to a bare "plan" pip; the live refresh is proven by the plan's next
 * weekly occurrence surfacing as a "plan" pip later in the cycle, no reload.
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
    // does (tx-row dispatches `edit-transaction`), switch Enter -> Plan, pick a
    // weekly cadence so the next occurrence lands inside the fortnightly window.
    $page->script("Livewire.dispatch('edit-transaction', { id: {$transaction->id} })");

    $page->assertPresent('.type-toggle')
        ->click('Plan')
        ->assertSee('Frequency')
        ->select('[wire\\:model="frequency"]', 'every-week')
        ->click('Convert to planned expense');

    // The converted posting reconciles to the new plan, so today keeps its
    // "out" pip; the plan's next weekly occurrence now surfaces as a "plan" pip
    // later in the cycle — proving the grid refreshed live, with no page reload.
    $page->assertPresent('.cyc-pip.plan')
        ->assertPresent('.cyc-pip.out');
});
