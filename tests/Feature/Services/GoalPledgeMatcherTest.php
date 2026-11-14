<?php

declare(strict_types=1);

use App\Enums\GoalPledgeStatus;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalAccount;
use App\Models\GoalPledge;
use App\Models\Transaction;
use App\Models\User;
use App\Services\GoalPledgeMatcher;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->goal = Goal::factory()->for($this->user)->create();
    GoalAccount::factory()->for($this->goal)->for($this->account)->create();
    $this->matcher = app(GoalPledgeMatcher::class);
});

function openPledge(Goal $goal, Account $account, int $amount = 20000): GoalPledge
{
    return GoalPledge::factory()->for($goal)->for($account)->create([
        'amount' => $amount,
        'created_at' => now(),
        'expires_at' => now()->addDays(7),
    ]);
}

function pledgeTransaction(User $user, Account $account, string $direction, int $amount, int $daysAfter = 2): Transaction
{
    return Transaction::factory()->for($user)->for($account)->{$direction}()->create([
        'amount' => $amount,
        'post_date' => now()->addDays($daysAfter),
    ]);
}

it('matches a pledge to a near-equal inflow but never an outflow', function () {
    $pledge = openPledge($this->goal, $this->account);
    pledgeTransaction($this->user, $this->account, 'debit', 20000);

    $this->matcher->matchForUser($this->user);
    expect($pledge->refresh()->status)->toBe(GoalPledgeStatus::Open);

    $inflow = pledgeTransaction($this->user, $this->account, 'credit', 19900);

    expect($this->matcher->matchForUser($this->user))->toBe(1)
        ->and($pledge->refresh()->status)->toBe(GoalPledgeStatus::Matched)
        ->and($pledge->matched_transaction_id)->toBe($inflow->id);
});

it('never matches a pledge to another user transaction', function () {
    $pledge = openPledge($this->goal, $this->account);
    $other = User::factory()->create();
    pledgeTransaction($other, $this->account, 'credit', 20000);
    pledgeTransaction($other, Account::factory()->for($other)->create(), 'credit', 20000);

    expect($this->matcher->matchForUser($this->user))->toBe(0)
        ->and($pledge->refresh()->status)->toBe(GoalPledgeStatus::Open);
});

it('claims a transaction for one pledge only', function () {
    $first = openPledge($this->goal, $this->account);
    $second = openPledge($this->goal, $this->account, 20010);
    $inflow = pledgeTransaction($this->user, $this->account, 'credit', 20000);

    expect($this->matcher->matchForUser($this->user))->toBe(1)
        ->and($first->refresh()->matched_transaction_id)->toBe($inflow->id)
        ->and($second->refresh()->status)->toBe(GoalPledgeStatus::Open);
});

it('rejects an inflow outside the amount tolerance or the date window', function () {
    $pledge = openPledge($this->goal, $this->account);
    pledgeTransaction($this->user, $this->account, 'credit', 19700);
    pledgeTransaction($this->user, $this->account, 'credit', 20000, 9);
    pledgeTransaction($this->user, $this->account, 'credit', 20000, -6);

    expect($this->matcher->matchForUser($this->user))->toBe(0)
        ->and($pledge->refresh()->status)->toBe(GoalPledgeStatus::Open);
});

it('accepts an inflow dated up to five days before the pledge', function () {
    $pledge = openPledge($this->goal, $this->account);
    pledgeTransaction($this->user, $this->account, 'credit', 20000, -4);

    expect($this->matcher->matchForUser($this->user))->toBe(1)
        ->and($pledge->refresh()->status)->toBe(GoalPledgeStatus::Matched);
});

it('leaves a pending inflow unmatched', function () {
    $pledge = openPledge($this->goal, $this->account);
    Transaction::factory()->for($this->user)->for($this->account)->credit()->create([
        'amount' => 20000,
        'post_date' => now()->addDay(),
        'status' => App\Enums\TransactionStatus::Pending,
    ]);

    expect($this->matcher->matchForUser($this->user))->toBe(0)
        ->and($pledge->refresh()->status)->toBe(GoalPledgeStatus::Open);
});

it('leaves an overdue pledge unmatched so its replacement claims the inflow', function () {
    $overdue = GoalPledge::factory()->for($this->goal)->for($this->account)->create([
        'amount' => 20000,
        'created_at' => now()->subDays(8),
        'expires_at' => now()->subDay(),
    ]);
    $replacement = openPledge($this->goal, $this->account);
    $inflow = pledgeTransaction($this->user, $this->account, 'credit', 20000, -1);

    expect($this->matcher->matchForUser($this->user))->toBe(1)
        ->and($overdue->refresh()->status)->toBe(GoalPledgeStatus::Open)
        ->and($replacement->refresh()->matched_transaction_id)->toBe($inflow->id);
});

it('keeps a claim across revisions of the matched transaction', function () {
    $first = openPledge($this->goal, $this->account);
    $original = pledgeTransaction($this->user, $this->account, 'credit', 20000);
    $this->matcher->matchForUser($this->user);
    $revision = $original->createChild(['notes' => 'edited']);
    $second = openPledge($this->goal, $this->account, 20010);

    expect($first->refresh()->status)->toBe(GoalPledgeStatus::Matched)
        ->and($this->matcher->matchForUser($this->user))->toBe(0)
        ->and($second->refresh()->status)->toBe(GoalPledgeStatus::Open)
        ->and($revision->parent_transaction_id)->toBe($original->id);
});

it('does not let a second pledge claim an inflow through its split children', function () {
    $first = openPledge($this->goal, $this->account);
    $parent = pledgeTransaction($this->user, $this->account, 'credit', 20000);
    $this->matcher->matchForUser($this->user);
    $parent->update(['excluded' => true]);

    foreach ([19900, 100] as $amount) {
        Transaction::factory()->for($this->user)->for($this->account)->credit()->create([
            'amount' => $amount,
            'post_date' => $parent->post_date,
            'split_parent_id' => $parent->id,
        ]);
    }

    $second = openPledge($this->goal, $this->account, 19900);

    expect($first->refresh()->status)->toBe(GoalPledgeStatus::Matched)
        ->and($this->matcher->matchForUser($this->user))->toBe(0)
        ->and($second->refresh()->status)->toBe(GoalPledgeStatus::Open);
});

it('keeps a claim when the matched revision is deleted and its predecessor is current again', function () {
    $original = pledgeTransaction($this->user, $this->account, 'credit', 20000);
    $revision = $original->createChild(['notes' => 'edited']);
    $first = openPledge($this->goal, $this->account);
    $this->matcher->matchForUser($this->user);
    $revision->delete();
    $second = openPledge($this->goal, $this->account, 20010);

    expect($first->refresh()->matched_transaction_id)->toBe($revision->id)
        ->and($this->matcher->matchForUser($this->user))->toBe(0)
        ->and($second->refresh()->status)->toBe(GoalPledgeStatus::Open);
});

it('floors the one percent tolerance so a 51 cent gap on a 50.01 pledge does not match', function () {
    $pledge = openPledge($this->goal, $this->account, 5001);
    pledgeTransaction($this->user, $this->account, 'credit', 5052);

    expect($this->matcher->matchForUser($this->user))->toBe(0)
        ->and($pledge->refresh()->status)->toBe(GoalPledgeStatus::Open);

    $near = pledgeTransaction($this->user, $this->account, 'credit', 5051);

    expect($this->matcher->matchForUser($this->user))->toBe(1)
        ->and($pledge->refresh()->matched_transaction_id)->toBe($near->id);
});

arch('goal pledge services use the model connection rather than the DB facade')
    ->expect([App\Services\GoalPledgeManager::class, GoalPledgeMatcher::class])
    ->not->toUse(Illuminate\Support\Facades\DB::class);
