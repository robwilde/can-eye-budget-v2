<?php

declare(strict_types=1);

use App\Enums\GoalPledgeStatus;
use App\Jobs\MaintainGoalsJob;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalAccount;
use App\Models\GoalPledge;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('expires open pledges past their expiry', function () {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();
    $account = Account::factory()->for($user)->create();
    $stale = GoalPledge::factory()->for($goal)->for($account)->create(['expires_at' => now()->subDay()]);
    $fresh = GoalPledge::factory()->for($goal)->for($account)->create(['expires_at' => now()->addDay()]);
    $other = GoalPledge::factory()->create(['expires_at' => now()->subDay()]);

    MaintainGoalsJob::dispatchSync($user);

    expect($stale->refresh()->status)->toBe(GoalPledgeStatus::Expired)
        ->and($fresh->refresh()->status)->toBe(GoalPledgeStatus::Open)
        ->and($other->refresh()->status)->toBe(GoalPledgeStatus::Open);
});

it('recalculates a months-of-expenses reserve target', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $reserve = Goal::factory()->reserve()->for($user)->create([
        'target_months_of_expenses' => 3,
        'target_amount' => 1,
    ]);
    GoalAccount::factory()->for($reserve)->for($account)->create();

    foreach ([1, 2, 3] as $monthsAgo) {
        Transaction::factory()->for($user)->for($account)->debit()->create([
            'amount' => 100000,
            'post_date' => $user->today()->startOfMonth()->subMonthsNoOverflow($monthsAgo)->addDays(4),
        ]);
    }

    MaintainGoalsJob::dispatchSync($user);

    expect($reserve->refresh()->target_amount)->toBe(300000);
});

it('leaves fixed-amount reserves and completed goals alone', function () {
    $user = User::factory()->create();
    $fixed = Goal::factory()->reserve()->for($user)->create(['target_amount' => 5000]);
    $archived = Goal::factory()->reserve()->archived()->for($user)->create([
        'target_months_of_expenses' => 3,
        'target_amount' => 5000,
    ]);

    MaintainGoalsJob::dispatchSync($user);

    expect($fixed->refresh()->target_amount)->toBe(5000)
        ->and($archived->refresh()->target_amount)->toBe(5000);
});

it('queues one job per user with goals when goals are enabled', function () {
    Queue::fake();
    config(['budget.goals_enabled' => true]);
    $withGoals = User::factory()->create();
    Goal::factory()->count(2)->for($withGoals)->create();
    User::factory()->create();

    $this->artisan('goals:maintain')->assertSuccessful();

    Queue::assertPushed(MaintainGoalsJob::class, 1);
    Queue::assertPushed(MaintainGoalsJob::class, fn (MaintainGoalsJob $job): bool => $job->user->is($withGoals));
});

it('queues nothing while goals are disabled', function () {
    Queue::fake();
    config(['budget.goals_enabled' => false]);
    Goal::factory()->create();

    $this->artisan('goals:maintain')->assertSuccessful();

    Queue::assertNothingPushed();
});
