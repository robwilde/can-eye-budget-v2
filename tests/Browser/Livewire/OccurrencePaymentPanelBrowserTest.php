<?php

/** @noinspection JSUnresolvedReference */
/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\PlannedOccurrence;
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;

test('the rules page opens the payment panel and attaches a ranked transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Everyday']);
    $user->update(['primary_account_id' => $account->id]);
    $plan = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create([
        'description' => 'Acme Power', 'amount' => 10000, 'direction' => TransactionDirection::Debit, 'merchant_key' => null, 'start_date' => now()->subMonths(2)->toDateString(), 'until_date' => null,
    ]);
    $occurrence = PlannedOccurrence::query()->where('planned_transaction_id', $plan->id)->whereDate('due_date', '<=', now()->toDateString())->orderByDesc('due_date')->firstOrFail();
    $transaction = Transaction::factory()->for($user)->for($account)->create([
        'description' => 'ACME POWER', 'amount' => -10000, 'direction' => TransactionDirection::Debit, 'post_date' => $occurrence->due_date->toDateString(), 'planned_transaction_id' => null,
    ]);

    $this->actingAs($user);

    visit('/rules')
        ->click("[data-testid=\"plan-occurrences-toggle-{$plan->id}\"]")
        ->click("[data-testid=\"occurrence-{$occurrence->id}\"] [data-testid=\"occurrence-payments\"]")
        ->wait(2)
        ->assertPresent('[data-testid="occurrence-payment-panel"]')
        ->assertPresent("[data-testid=\"candidate-{$transaction->id}\"]")
        ->assertSee('Best matches')
        ->screenshot(filename: 'occurrence-payment-panel')
        ->click("[data-testid=\"candidate-{$transaction->id}\"] [data-testid=\"candidate-attach\"]")
        ->wait(2)
        ->assertPresent('[data-testid="payment-source"]')
        ->assertNoJavaScriptErrors();

    expect(PlannedPayment::query()->where('transaction_id', $transaction->id)->sole()->amount)->toBe(10000)
        ->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Paid);
});
