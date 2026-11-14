<?php

declare(strict_types=1);

use App\Enums\GoalKind;
use App\Enums\GoalStatus;
use App\Exceptions\GoalRefusedException;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalAccount;
use App\Models\GoalSpend;
use App\Models\Transaction;
use App\Models\User;
use App\Services\GoalManager;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->manager = app(GoalManager::class);
});

function goalData(array $accounts, array $overrides = []): array
{
    return array_merge([
        'name' => 'Holiday',
        'target_amount' => 100000,
        'accounts' => $accounts,
    ], $overrides);
}

function ownedAccount(User $user, int $balance = 100000): Account
{
    return Account::factory()->for($user)->create(['balance' => $balance]);
}

function activeGoalOn(User $user, Account $account, ?int $earmark = null, array $attributes = []): Goal
{
    $goal = Goal::factory()->for($user)->create($attributes);
    GoalAccount::factory()->for($goal)->for($account)->create(['earmark_amount' => $earmark]);

    return $goal;
}

it('refuses to save a goal without a funding account', function () {
    expect(fn () => $this->manager->create($this->user, goalData([])))
        ->toThrow(GoalRefusedException::class, 'Pick at least one account to fund this goal.')
        ->and(Goal::query()->count())->toBe(0);
});

it('refuses to link another user account to a goal', function () {
    $foreign = ownedAccount(User::factory()->create());

    expect(fn () => $this->manager->create($this->user, goalData([['account_id' => $foreign->id]])))
        ->toThrow(GoalRefusedException::class)
        ->and(Goal::query()->count())->toBe(0)
        ->and(GoalAccount::query()->count())->toBe(0);
});

it('refuses a second goal claiming a whole account', function () {
    $account = ownedAccount($this->user);
    activeGoalOn($this->user, $account);

    expect(fn () => $this->manager->create($this->user, goalData([['account_id' => $account->id]])))
        ->toThrow(GoalRefusedException::class)
        ->and(Goal::query()->count())->toBe(1);
});

it('allows a second goal to earmark part of an account claimed whole', function () {
    $account = ownedAccount($this->user);
    activeGoalOn($this->user, $account);

    $goal = $this->manager->create($this->user, goalData([['account_id' => $account->id, 'earmark_amount' => 5000]]));

    expect($goal->goalAccounts()->first()->earmark_amount)->toBe(5000);
});

it('refuses to complete a maintained reserve', function () {
    $goal = Goal::factory()->reserve()->for($this->user)->create();
    GoalAccount::factory()->for($goal)->for(ownedAccount($this->user))->create();

    expect(fn () => $this->manager->complete($this->user, $goal))
        ->toThrow(GoalRefusedException::class)
        ->and($goal->refresh()->status)->toBe(GoalStatus::Active);
});

it('refuses a goal spend larger than the earmark', function () {
    $goal = activeGoalOn($this->user, ownedAccount($this->user, 100000), 30000);

    expect(fn () => $this->manager->recordSpend($this->user, $goal, 40000))
        ->toThrow(GoalRefusedException::class)
        ->and(GoalSpend::query()->count())->toBe(0);
});

it('clears the target date of a reserve on save', function () {
    $account = ownedAccount($this->user);

    $goal = $this->manager->create($this->user, goalData([['account_id' => $account->id]], [
        'kind' => GoalKind::Reserve,
        'target_date' => '2030-01-01',
    ]));

    expect($goal->target_date)->toBeNull();
});

it('computes a months-of-expenses target from the median monthly spend', function () {
    $account = ownedAccount($this->user);

    foreach ([1, 2, 3] as $monthsAgo) {
        Transaction::factory()->for($this->user)->for($account)->debit()->create([
            'amount' => 100000,
            'post_date' => $this->user->today()->startOfMonth()->subMonthsNoOverflow($monthsAgo)->addDays(4),
        ]);
    }

    $goal = $this->manager->create($this->user, goalData([['account_id' => $account->id]], [
        'kind' => GoalKind::Reserve,
        'target_months_of_expenses' => 3,
    ]));

    expect($goal->target_amount)->toBe(300000)
        ->and($goal->target_months_of_expenses)->toBe(3);
});

it('moves a goal through pause, resume, complete and reopen', function () {
    $goal = activeGoalOn($this->user, ownedAccount($this->user, 50000));

    expect($this->manager->pause($this->user, $goal)->status)->toBe(GoalStatus::Paused)
        ->and($this->manager->resume($this->user, $goal)->status)->toBe(GoalStatus::Active);

    $completed = $this->manager->complete($this->user, $goal);
    expect($completed->status)->toBe(GoalStatus::Completed)
        ->and($completed->frozen_saved_amount)->toBe(50000);

    $reopened = $this->manager->reopen($this->user, $goal);
    expect($reopened->status)->toBe(GoalStatus::Active)
        ->and($reopened->frozen_saved_amount)->toBeNull()
        ->and($reopened->completed_at)->toBeNull();
});

it('refuses to unarchive a goal whose whole account was claimed meanwhile', function () {
    $account = ownedAccount($this->user);
    $archived = activeGoalOn($this->user, $account);
    $this->manager->archive($this->user, $archived);
    activeGoalOn($this->user, $account);

    expect(fn () => $this->manager->unarchive($this->user, $archived))
        ->toThrow(GoalRefusedException::class)
        ->and($archived->refresh()->status)->toBe(GoalStatus::Archived);
});

it('releases the whole-account claim when a goal is archived and keeps it when paused', function () {
    $account = ownedAccount($this->user);
    $goal = activeGoalOn($this->user, $account);

    $this->manager->pause($this->user, $goal);
    expect(fn () => $this->manager->create($this->user, goalData([['account_id' => $account->id]])))
        ->toThrow(GoalRefusedException::class);

    $this->manager->archive($this->user, $goal);
    $second = $this->manager->create($this->user, goalData([['account_id' => $account->id]]));

    expect($second->exists)->toBeTrue();
});

it('records a spend, shrinking the earmark and still counting toward progress', function () {
    $goal = activeGoalOn($this->user, ownedAccount($this->user, 100000), 30000);

    $spend = $this->manager->recordSpend($this->user, $goal, 10000);

    expect($spend->amount)->toBe(10000)
        ->and($goal->goalAccounts()->first()->earmark_amount)->toBe(20000)
        ->and(app(App\Services\GoalProgressCalculator::class)->savedAmount($goal))->toBe(30000);
});

it('refuses spends that are not positive, on reserves or inactive goals, or over target', function () {
    $account = ownedAccount($this->user);
    $goal = activeGoalOn($this->user, $account, null, ['target_amount' => 1000]);
    $reserve = activeGoalOn($this->user, ownedAccount($this->user), null, ['kind' => GoalKind::Reserve]);
    $paused = activeGoalOn($this->user, ownedAccount($this->user), null, ['status' => GoalStatus::Paused]);

    expect(fn () => $this->manager->recordSpend($this->user, $goal, 0))->toThrow(GoalRefusedException::class)
        ->and(fn () => $this->manager->recordSpend($this->user, $reserve, 100))->toThrow(GoalRefusedException::class)
        ->and(fn () => $this->manager->recordSpend($this->user, $paused, 100))->toThrow(GoalRefusedException::class)
        ->and(fn () => $this->manager->recordSpend($this->user, $goal, 1001))->toThrow(GoalRefusedException::class)
        ->and(GoalSpend::query()->count())->toBe(0);
});

it('refuses a spend with no account chosen when several are linked', function () {
    $goal = activeGoalOn($this->user, ownedAccount($this->user));
    GoalAccount::factory()->for($goal)->for(ownedAccount($this->user))->create();

    expect(fn () => $this->manager->recordSpend($this->user, $goal, 100))
        ->toThrow(GoalRefusedException::class);
});

it('deletes only the goal and its links', function () {
    $account = ownedAccount($this->user);
    $goal = activeGoalOn($this->user, $account);

    $this->manager->delete($this->user, $goal);

    expect(Goal::query()->count())->toBe(0)
        ->and(GoalAccount::query()->count())->toBe(0)
        ->and(Account::query()->whereKey($account->id)->exists())->toBeTrue();
});

it('never acts on another user goal', function () {
    $other = User::factory()->create();
    $goal = activeGoalOn($other, ownedAccount($other));

    expect(fn () => $this->manager->pause($this->user, $goal))->toThrow(GoalRefusedException::class)
        ->and($goal->refresh()->status)->toBe(GoalStatus::Active);
});

it('computes the months-of-expenses target from reportable split children', function () {
    $account = ownedAccount($this->user);

    foreach ([1, 2, 3] as $monthsAgo) {
        $date = $this->user->today()->startOfMonth()->subMonthsNoOverflow($monthsAgo)->addDays(4);
        $parent = Transaction::factory()->for($this->user)->for($account)->debit()->create([
            'amount' => 100000,
            'post_date' => $date,
            'excluded' => true,
        ]);

        Transaction::factory()->for($this->user)->for($account)->debit()->create([
            'amount' => 70000,
            'post_date' => $date,
            'split_parent_id' => $parent->id,
        ]);
        Transaction::factory()->for($this->user)->for($account)->debit()->create([
            'amount' => 30000,
            'post_date' => $date,
            'split_parent_id' => $parent->id,
            'excluded' => true,
        ]);
    }

    $goal = $this->manager->create($this->user, goalData([['account_id' => $account->id]], [
        'kind' => GoalKind::Reserve,
        'target_months_of_expenses' => 3,
    ]));

    expect($goal->target_amount)->toBe(210000);
});

it('keeps recorded spends when a funding account is deleted', function () {
    $spentFrom = ownedAccount($this->user, 100000);
    $other = ownedAccount($this->user, 20000);
    $goal = activeGoalOn($this->user, $spentFrom);
    GoalAccount::factory()->for($goal)->for($other)->create();
    $this->manager->recordSpend($this->user, $goal, 10000, $spentFrom);

    $spentFrom->delete();

    expect(GoalSpend::query()->where('goal_id', $goal->id)->value('amount'))->toBe(10000)
        ->and(GoalSpend::query()->where('goal_id', $goal->id)->value('account_id'))->toBeNull()
        ->and(app(App\Services\GoalProgressCalculator::class)->savedAmount($goal))->toBe(30000);
});

it('keeps zero-spend months once spending history has begun before the window', function () {
    $account = ownedAccount($this->user);

    foreach ([8, 1] as $monthsAgo) {
        Transaction::factory()->for($this->user)->for($account)->debit()->create([
            'amount' => 100000,
            'post_date' => $this->user->today()->startOfMonth()->subMonthsNoOverflow($monthsAgo)->addDays(4),
        ]);
    }

    $goal = $this->manager->create($this->user, goalData([['account_id' => $account->id]], [
        'kind' => GoalKind::Reserve,
        'target_months_of_expenses' => 3,
    ]));

    expect($goal->target_amount)->toBe(0);
});

it('refuses a spend tied to a superseded transaction version', function () {
    $account = ownedAccount($this->user, 100000);
    $goal = activeGoalOn($this->user, $account);
    $original = Transaction::factory()->for($this->user)->for($account)->debit()->create(['amount' => 5000]);
    $original->createChild(['direction' => App\Enums\TransactionDirection::Credit]);

    expect(fn () => $this->manager->recordSpend($this->user, $goal, 1000, $account, $original))
        ->toThrow(GoalRefusedException::class)
        ->and(GoalSpend::query()->count())->toBe(0);
});

it('refuses to fund a goal from a liability or insurance account', function () {
    foreach ([App\Enums\AccountClass::CreditCard, App\Enums\AccountClass::Loan, App\Enums\AccountClass::Mortgage, App\Enums\AccountClass::Insurance] as $class) {
        $ineligible = Account::factory()->for($this->user)->create(['type' => $class, 'balance' => 100000]);

        expect(fn () => $this->manager->create($this->user, goalData([['account_id' => $ineligible->id]])))
            ->toThrow(GoalRefusedException::class, 'Choose cash or investment accounts to fund this goal.');
    }

    expect(Goal::query()->count())->toBe(0)->and(GoalAccount::query()->count())->toBe(0);
});

it('funds a goal from savings and investment accounts', function () {
    $savings = Account::factory()->for($this->user)->create(['type' => App\Enums\AccountClass::Savings]);
    $investment = Account::factory()->for($this->user)->create(['type' => App\Enums\AccountClass::Investment]);

    $goal = $this->manager->create($this->user, goalData([['account_id' => $savings->id, 'earmark_amount' => 1000], ['account_id' => $investment->id, 'earmark_amount' => 1000]]));

    expect($goal->goalAccounts()->count())->toBe(2);
});

it('counts allocation lines rather than the transaction category when finding the median spend', function () {
    $account = ownedAccount($this->user);
    $transfer = App\Models\Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Transfer']);
    $groceries = App\Models\Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Groceries']);

    foreach ([1, 2, 3] as $monthsAgo) {
        $transaction = Transaction::factory()->for($this->user)->for($account)->debit()->create([
            'amount' => 100000,
            'category_id' => $transfer->id,
            'post_date' => $this->user->today()->startOfMonth()->subMonthsNoOverflow($monthsAgo)->addDays(4),
        ]);
        App\Models\TransactionSplit::factory()->create(['transaction_id' => $transaction->id, 'category_id' => $groceries->id, 'amount' => 60000, 'position' => 0]);
        App\Models\TransactionSplit::factory()->create(['transaction_id' => $transaction->id, 'category_id' => $transfer->id, 'amount' => 40000, 'position' => 1]);
    }

    $goal = $this->manager->create($this->user, goalData([['account_id' => $account->id]], [
        'kind' => GoalKind::Reserve,
        'target_months_of_expenses' => 3,
    ]));

    expect($goal->target_amount)->toBe(180000);
});

it('refuses to fund one goal from accounts in different currencies', function () {
    $aud = Account::factory()->for($this->user)->create(['currency' => 'AUD']);
    $usd = Account::factory()->for($this->user)->create(['currency' => 'USD']);

    expect(fn () => $this->manager->create($this->user, goalData([['account_id' => $aud->id], ['account_id' => $usd->id]])))
        ->toThrow(GoalRefusedException::class, 'Choose accounts in one currency to fund this goal.')
        ->and(Goal::query()->count())->toBe(0);

    $other = Account::factory()->for($this->user)->create(['currency' => 'AUD']);
    $goal = $this->manager->create($this->user, goalData([['account_id' => $aud->id], ['account_id' => $other->id]]));

    expect(fn () => $this->manager->update($this->user, $goal, goalData([['account_id' => $aud->id], ['account_id' => $usd->id]])))
        ->toThrow(GoalRefusedException::class)
        ->and($goal->goalAccounts()->count())->toBe(2);
});

arch('goal services use the model connection and model queries, not the DB facade')
    ->expect([GoalManager::class, App\Services\GoalProgressCalculator::class, App\Services\MedianMonthlySpend::class])
    ->not->toUse(Illuminate\Support\Facades\DB::class);
