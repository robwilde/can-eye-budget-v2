<?php

/** @noinspection JSUnresolvedReference */
/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PlannedTransactionType;
use App\Enums\PlanOrigin;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;

function declarationUser(): User
{
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Everyday']);
    $user->update(['primary_account_id' => $account->id]);

    return $user;
}

test('the rules page declares a bill, then refuses the same bill again', function () {
    $user = declarationUser();
    $this->actingAs($user);

    $page = visit('/rules');

    $page->click('[data-testid="add-bill"]')
        ->assertSee('First due date')
        ->fill('[data-testid="declare-description"]', 'Rent')
        ->fill('[data-testid="declare-amount"]', '100')
        ->screenshot(filename: 'plan-declaration-bill-form')
        ->click('[data-testid="declare-save"]')
        ->wait(2)
        ->assertSee('Rent')
        ->click('[data-testid="add-bill"]')
        ->fill('[data-testid="declare-description"]', 'Rent')
        ->fill('[data-testid="declare-amount"]', '100')
        ->click('[data-testid="declare-save"]')
        ->wait(2)
        ->assertSee('This bill already exists on that account.')
        ->screenshot(filename: 'plan-declaration-duplicate')
        ->assertNoJavaScriptErrors();

    $plan = PlannedTransaction::query()->where('user_id', $user->id)->sole();

    expect($plan->origin)->toBe(PlanOrigin::Declared)
        ->and($plan->type)->toBe(PlannedTransactionType::Bill)
        ->and($plan->amount)->toBe(10000);
});

test('the rules page declares income every two weeks', function () {
    $user = declarationUser();
    $this->actingAs($user);

    $page = visit('/rules');

    $page->click('[data-testid="add-income"]')
        ->assertSee('First pay date')
        ->fill('[data-testid="declare-description"]', 'Salary')
        ->fill('[data-testid="declare-amount"]', '2000')
        ->click('[data-testid="declare-save"]')
        ->wait(2)
        ->assertSee('Salary')
        ->assertNoJavaScriptErrors();

    $plan = PlannedTransaction::query()->where('user_id', $user->id)->sole();

    expect($plan->direction)->toBe(TransactionDirection::Credit)
        ->and($plan->amount)->toBe(200000)
        ->and($plan->frequency->value)->toBe('every-2-weeks');
});

test('the add bill form offers a detected pattern and prefills from it', function () {
    $user = declarationUser();
    $account = Account::factory()->for($user)->create(['name' => 'Cards']);

    foreach ([2, 1, 0] as $monthsAgo) {
        Transaction::factory()->fromRedbark()->for($user)->for($account)->create([
            'merchant_name' => 'Netflix',
            'amount' => 1699,
            'direction' => TransactionDirection::Debit,
            'post_date' => now()->subMonthsNoOverflow($monthsAgo)->subDays(2)->toDateString(),
        ]);
    }

    $this->actingAs($user);

    $page = visit('/rules');

    $page->click('[data-testid="add-bill"]')
        ->assertPresent('[data-testid="declare-patterns"]')
        ->assertSeeIn('[data-testid="declare-pattern"]', 'Netflix')
        ->click('[data-testid="declare-pattern"]')
        ->wait(1)
        ->assertValue('[data-testid="declare-description"]', 'Netflix')
        ->assertValue('[data-testid="declare-amount"]', '16.99')
        ->screenshot(filename: 'plan-declaration-pattern')
        ->assertNoJavaScriptErrors();
});

test('the add income form searches the user transactions and starts from one', function () {
    $user = declarationUser();
    $account = $user->primaryAccount;
    Transaction::factory()->for($user)->for($account)->create([
        'description' => 'Pay run ACME',
        'amount' => 250000,
        'direction' => TransactionDirection::Credit,
        'post_date' => now()->subDays(5)->toDateString(),
    ]);

    $this->actingAs($user);

    $page = visit('/rules');

    $page->click('[data-testid="add-bill"]')
        ->click('[data-testid="declare-search-panel"] summary')
        ->fill('[data-testid="declare-search"]', 'ACME')
        ->wait(2)
        ->assertSeeIn('[data-testid="declare-found"]', 'Pay run ACME')
        ->screenshot(filename: 'plan-declaration-search')
        ->click('[data-testid="declare-found"]')
        ->wait(1)
        ->assertSee('First pay date')
        ->assertValue('[data-testid="declare-amount"]', '2500.00')
        ->assertNoJavaScriptErrors();
});
