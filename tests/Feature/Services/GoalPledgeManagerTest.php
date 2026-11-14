<?php

declare(strict_types=1);

use App\Enums\GoalPledgeStatus;
use App\Exceptions\GoalRefusedException;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalAccount;
use App\Models\GoalPledge;
use App\Models\User;
use App\Services\GoalPledgeManager;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->goal = Goal::factory()->for($this->user)->create();
    GoalAccount::factory()->for($this->goal)->for($this->account)->create();
    $this->manager = app(GoalPledgeManager::class);
});

it('refuses a duplicate open pledge for the same goal, account and amount', function () {
    $this->manager->pledge($this->user, $this->goal, $this->account, 20000);

    expect(fn () => $this->manager->pledge($this->user, $this->goal, $this->account, 20000))
        ->toThrow(GoalRefusedException::class)
        ->and(GoalPledge::query()->count())->toBe(1);

    $this->manager->pledge($this->user, $this->goal, $this->account, 20001);

    expect(GoalPledge::query()->count())->toBe(2);
});

it('opens a pledge for seven days and renews it by seven more', function () {
    $pledge = $this->manager->pledge($this->user, $this->goal, $this->account, 20000);
    $opened = $pledge->expires_at;

    expect($opened->isSameDay(now()->addDays(7)))->toBeTrue()
        ->and($this->manager->renew($this->user, $pledge)->expires_at->equalTo($opened->addDays(7)))->toBeTrue();
});

it('cancels an open pledge and refuses to renew or cancel it again', function () {
    $pledge = $this->manager->pledge($this->user, $this->goal, $this->account, 20000);

    expect($this->manager->cancel($this->user, $pledge)->status)->toBe(GoalPledgeStatus::Cancelled)
        ->and(fn () => $this->manager->renew($this->user, $pledge))->toThrow(GoalRefusedException::class)
        ->and(fn () => $this->manager->cancel($this->user, $pledge))->toThrow(GoalRefusedException::class);
});

it('refuses a pledge on an account that does not fund the goal or with no amount', function () {
    $unlinked = Account::factory()->for($this->user)->create();

    expect(fn () => $this->manager->pledge($this->user, $this->goal, $unlinked, 100))->toThrow(GoalRefusedException::class)
        ->and(fn () => $this->manager->pledge($this->user, $this->goal, $this->account, 0))->toThrow(GoalRefusedException::class)
        ->and(GoalPledge::query()->count())->toBe(0);
});

it('never acts on another user goal or pledge', function () {
    $other = User::factory()->create();
    $pledge = GoalPledge::factory()->for($this->goal)->for($this->account)->create();

    expect(fn () => $this->manager->pledge($other, $this->goal, $this->account, 100))->toThrow(GoalRefusedException::class)
        ->and(fn () => $this->manager->cancel($other, $pledge))->toThrow(GoalRefusedException::class)
        ->and($pledge->refresh()->status)->toBe(GoalPledgeStatus::Open);
});

it('refuses to renew an overdue pledge so a replacement never duplicates it', function () {
    $overdue = GoalPledge::factory()->for($this->goal)->for($this->account)->create([
        'amount' => 20000,
        'expires_at' => now()->subHour(),
    ]);
    $replacement = $this->manager->pledge($this->user, $this->goal, $this->account, 20000);

    expect(fn () => $this->manager->renew($this->user, $overdue))->toThrow(GoalRefusedException::class)
        ->and($overdue->refresh()->expires_at->isPast())->toBeTrue()
        ->and($replacement->refresh()->status)->toBe(GoalPledgeStatus::Open);
});
