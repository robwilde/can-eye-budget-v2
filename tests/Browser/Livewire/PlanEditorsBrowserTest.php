<?php

/** @noinspection JSUnresolvedReference */
/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PlannedTransactionType;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\User;

test('the rules page plan editor saves the type, payment link and end count and renders a safe link', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Everyday']);
    $user->update(['primary_account_id' => $account->id]);
    $plan = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create([
        'description' => 'Netflix',
        'amount' => 2299,
    ]);

    $this->actingAs($user);

    $page = visit('/rules');

    $page->assertSee('Netflix')
        ->click("button[wire\\:click=\"openEdit({$plan->id})\"]")
        ->assertSee('Edit planned transaction')
        ->assertPresent('[data-testid="plan-series-fields"]')
        ->select('[data-testid="plan-type-select"]', 'subscription')
        ->fill('[data-testid="plan-payment-url"]', 'netflix.example/pay')
        ->fill('[data-testid="plan-end-after-count"]', '12')
        ->screenshot(filename: 'plan-editor-manager-open')
        ->click('Update')
        ->assertSee('Subscription')
        ->assertPresent('a[data-testid="plan-payment-link"]')
        ->assertAttribute('a[data-testid="plan-payment-link"]', 'href', 'https://netflix.example/pay')
        ->assertAttribute('a[data-testid="plan-payment-link"]', 'rel', 'noopener noreferrer')
        ->assertNoJavaScriptErrors();

    $fresh = $plan->fresh();

    expect($fresh->type)->toBe(PlannedTransactionType::Subscription)
        ->and($fresh->payment_url)->toBe('https://netflix.example/pay')
        ->and($fresh->end_after_count)->toBe(12);
});

test('the rules page plan editor refuses a script payment link and keeps the form open', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $user->update(['primary_account_id' => $account->id]);
    $plan = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create(['description' => 'Water']);

    $this->actingAs($user);

    $page = visit('/rules');

    $page->click("button[wire\\:click=\"openEdit({$plan->id})\"]")
        ->fill('[data-testid="plan-payment-url"]', 'javascript:alert(1)')
        ->click('Update')
        ->assertSee('Enter a payment link that starts with http:// or https://.')
        ->assertSee('Edit planned transaction')
        ->assertNoJavaScriptErrors();

    expect($plan->fresh()->payment_url)->toBeNull();
});

test('the rules page plan editor locks the type on a transfer plan', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $destination = Account::factory()->for($user)->create();
    $user->update(['primary_account_id' => $account->id]);
    $plan = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create([
        'description' => 'Move to savings',
        'transfer_to_account_id' => $destination->id,
    ]);

    $this->actingAs($user);

    $page = visit('/rules');

    $page->click("button[wire\\:click=\"openEdit({$plan->id})\"]")
        ->assertSee('A plan with a destination account is always a transfer.')
        ->assertAttribute('[data-testid="plan-type-select"]', 'disabled', 'disabled')
        ->screenshot(filename: 'plan-editor-manager-transfer')
        ->assertNoJavaScriptErrors();
});

test('the calendar plan modal retypes a credit plan, ends after a count and shows the series fields', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $plan = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create([
        'description' => 'Rent received',
        'amount' => 250000,
        'start_date' => now()->addDays(3)->toDateString(),
    ]);

    $this->actingAs($user);

    $page = visit('/calendar');

    $page->script("Livewire.dispatch('edit-planned-transaction', { id: {$plan->id} })");

    $page->assertPresent('[data-testid="plan-series-fields"]')
        ->assertPresent('[data-testid="merchant-picker"]')
        ->select('[data-testid="transaction-type"]', 'income')
        ->click('[data-testid="until-after-count"]')
        ->fill('[data-testid="plan-end-after-count"]', '6')
        ->fill('[data-testid="plan-payment-url"]', 'landlord.example/pay')
        ->screenshot(filename: 'plan-editor-modal-open')
        ->click('[data-testid="transaction-submit"]')
        ->wait(2)
        ->assertNoJavaScriptErrors();

    $fresh = $plan->fresh();

    expect($fresh->type)->toBe(PlannedTransactionType::Income)
        ->and($fresh->end_after_count)->toBe(6)
        ->and($fresh->payment_url)->toBe('https://landlord.example/pay');
});

test('the calendar plan modal saves an installment that has an end date and no payment count', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $plan = PlannedTransaction::factory()->installment()->monthly()->for($user)->for($account)->create([
        'description' => 'Sofa',
        'start_date' => now()->addDays(3)->toDateString(),
        'until_date' => now()->addMonths(6)->toDateString(),
    ]);

    $this->actingAs($user);

    $page = visit('/calendar');

    $page->script("Livewire.dispatch('edit-planned-transaction', { id: {$plan->id} })");

    $page->assertPresent('[data-testid="plan-end-after-count"]')
        ->click('[data-testid="plan-autopay"]')
        ->click('[data-testid="transaction-submit"]')
        ->wait(2)
        ->assertNoJavaScriptErrors();

    expect($plan->fresh()->autopay)->toBeFalse()
        ->and($plan->fresh()->until_date)->not->toBeNull();
});

test('the copy link option appears once a payment link is typed in the rules page editor', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $user->update(['primary_account_id' => $account->id]);
    $plan = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create(['description' => 'Water']);

    $this->actingAs($user);

    $page = visit('/rules');

    $page->click("button[wire\\:click=\"openEdit({$plan->id})\"]")
        ->assertNotPresent('[data-testid="plan-copy-payment-url"]')
        ->fill('[data-testid="plan-payment-url"]', 'water.example/pay')
        ->script("document.querySelector('[data-testid=\"plan-payment-url\"]').blur()");

    $page->wait(1)
        ->assertPresent('[data-testid="plan-copy-payment-url"]')
        ->assertNoJavaScriptErrors();
});
