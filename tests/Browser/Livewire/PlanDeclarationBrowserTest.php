<?php

/** @noinspection JSUnresolvedReference */
/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\TypeSafeServiceContract;
use App\DTOs\TypeSafeChoiceAnswer;
use App\Enums\PlannedTransactionType;
use App\Enums\PlanOrigin;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Recurring\PlanSmartFiller;

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

test('the add bill form proposes smart fill values and keeps them unsaved until added', function () {
    config(['budget.plan_smart_fill' => true, 'services.typesafe.api_key' => 'ts_test']);
    $user = declarationUser();

    foreach ([2, 1, 0] as $monthsAgo) {
        Transaction::factory()->for($user)->for($user->primaryAccount)->create([
            'description' => 'Netflix',
            'amount' => -1699,
            'direction' => TransactionDirection::Debit,
            'post_date' => now()->subMonthsNoOverflow($monthsAgo)->subDays(2)->toDateString(),
        ]);
    }

    app()->when(PlanSmartFiller::class)->needs(TypeSafeServiceContract::class)->give(fn () => new class implements TypeSafeServiceContract
    {
        public function choose(array $state, string $instructions, array $options): TypeSafeChoiceAnswer
        {
            $choice = str_contains($instructions, 'How often') ? 'every-month' : (str_contains($instructions, 'kind of payment') ? 'subscription' : array_key_first($options));

            return new TypeSafeChoiceAnswer((string) $choice, [(string) $choice => 0.9], 0.9, 'fake', 1, 1);
        }
    });

    $this->actingAs($user);

    $page = visit('/rules');

    $page->click('[data-testid="add-bill"]')
        ->fill('[data-testid="declare-description"]', 'Netflix')
        ->click('[data-testid="declare-smart-fill-button"]')
        ->wait(2)
        ->assertSee('nothing is saved until you add the plan')
        ->assertValue('[data-testid="declare-amount"]', '16.99')
        ->assertValue('[data-testid="declare-type"]', 'subscription')
        ->screenshot(filename: 'plan-declaration-smart-fill')
        ->assertNoJavaScriptErrors();

    expect(PlannedTransaction::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('the plans page lists what smart configure would change and changes nothing', function () {
    config(['budget.plan_smart_fill' => true, 'services.typesafe.api_key' => 'ts_test']);
    $user = declarationUser();

    foreach ([2, 1, 0] as $monthsAgo) {
        Transaction::factory()->for($user)->for($user->primaryAccount)->create([
            'description' => 'Netflix',
            'amount' => -1699,
            'direction' => TransactionDirection::Debit,
            'post_date' => now()->subMonthsNoOverflow($monthsAgo)->subDays(2)->toDateString(),
        ]);
    }

    $plan = PlannedTransaction::factory()->for($user)->for($user->primaryAccount)->monthly()->create([
        'description' => 'Netflix',
        'amount' => 1499,
        'direction' => TransactionDirection::Debit,
    ]);

    app()->when(PlanSmartFiller::class)->needs(TypeSafeServiceContract::class)->give(fn () => new class implements TypeSafeServiceContract
    {
        public function choose(array $state, string $instructions, array $options): TypeSafeChoiceAnswer
        {
            $choice = array_key_first($options);

            return new TypeSafeChoiceAnswer((string) $choice, [(string) $choice => 0.9], 0.9, 'fake', 1, 1);
        }
    });

    $this->actingAs($user);

    $page = visit('/rules');

    $page->click("[data-testid=\"plan-smart-configure-{$plan->id}\"]")
        ->wait(2)
        ->assertSeeIn('[data-testid="plan-smart-configure-result"]', 'Amount')
        ->assertSeeIn('[data-testid="plan-smart-configure-result"]', '$16.99')
        ->screenshot(filename: 'plan-smart-configure')
        ->assertNoJavaScriptErrors();

    expect($plan->fresh()->amount)->toBe(1499);
});
