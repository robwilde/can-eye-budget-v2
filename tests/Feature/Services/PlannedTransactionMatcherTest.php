<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PlannedTransactionMatcher;
use App\Services\ReconciliationMatcher;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::create(2026, 6, 15));
    $this->matcher = app(PlannedTransactionMatcher::class);
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function activeRentPlan(User $user, Account $account, string $startDate = '2026-06-10'): PlannedTransaction
{
    return PlannedTransaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'amount' => 50000,
        'direction' => TransactionDirection::Debit,
        'frequency' => RecurrenceFrequency::DontRepeat,
        'start_date' => $startDate,
        'is_active' => true,
    ]);
}

test('matches an unmatched transaction on the same day as a plan occurrence', function () {
    $plan = activeRentPlan($this->user, $this->account);

    $tx = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => -50000,
        'post_date' => '2026-06-10',
        'planned_transaction_id' => null,
    ]);

    expect($this->matcher->matchForUser($this->user))->toBe(1)
        ->and($tx->fresh()->planned_transaction_id)->toBe($plan->id);
});

test('matches within the date tolerance', function () {
    $plan = activeRentPlan($this->user, $this->account);

    $tx = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => -50000,
        'post_date' => CarbonImmutable::create(2026, 6, 10)->addDays(ReconciliationMatcher::DATE_TOLERANCE_DAYS)->toDateString(),
        'planned_transaction_id' => null,
    ]);

    expect($this->matcher->matchForUser($this->user))->toBe(1)
        ->and($tx->fresh()->planned_transaction_id)->toBe($plan->id);
});

test('does not match outside the date tolerance', function () {
    activeRentPlan($this->user, $this->account);

    $tx = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => -50000,
        'post_date' => CarbonImmutable::create(2026, 6, 10)->addDays(ReconciliationMatcher::DATE_TOLERANCE_DAYS + 1)->toDateString(),
        'planned_transaction_id' => null,
    ]);

    expect($this->matcher->matchForUser($this->user))->toBe(0)
        ->and($tx->fresh()->planned_transaction_id)->toBeNull();
});

test('matches within the amount tolerance (±10%)', function () {
    $plan = activeRentPlan($this->user, $this->account);

    $tx = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => -47500,
        'post_date' => '2026-06-10',
        'planned_transaction_id' => null,
    ]);

    expect($this->matcher->matchForUser($this->user))->toBe(1)
        ->and($tx->fresh()->planned_transaction_id)->toBe($plan->id);
});

test('does not match outside the amount tolerance', function () {
    activeRentPlan($this->user, $this->account);

    $tx = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => -40000,
        'post_date' => '2026-06-10',
        'planned_transaction_id' => null,
    ]);

    expect($this->matcher->matchForUser($this->user))->toBe(0)
        ->and($tx->fresh()->planned_transaction_id)->toBeNull();
});

test('does not match a transaction on a different account', function () {
    activeRentPlan($this->user, $this->account);
    $other = Account::factory()->for($this->user)->create();

    $tx = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $other->id,
        'amount' => -50000,
        'post_date' => '2026-06-10',
        'planned_transaction_id' => null,
    ]);

    expect($this->matcher->matchForUser($this->user))->toBe(0)
        ->and($tx->fresh()->planned_transaction_id)->toBeNull();
});

test('does not match the wrong direction', function () {
    activeRentPlan($this->user, $this->account);

    $tx = Transaction::factory()->for($this->user)->credit()->create([
        'account_id' => $this->account->id,
        'amount' => 50000,
        'post_date' => '2026-06-10',
        'planned_transaction_id' => null,
    ]);

    expect($this->matcher->matchForUser($this->user))->toBe(0)
        ->and($tx->fresh()->planned_transaction_id)->toBeNull();
});

test('never matches a transfer leg', function () {
    activeRentPlan($this->user, $this->account);

    $pair = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => -50000,
        'post_date' => '2026-06-10',
    ]);

    $transfer = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => -50000,
        'post_date' => '2026-06-10',
        'transfer_pair_id' => $pair->id,
        'planned_transaction_id' => null,
    ]);

    $this->matcher->matchForUser($this->user);

    expect($transfer->fresh()->planned_transaction_id)->toBeNull();
});

test('does not match an inactive plan', function () {
    PlannedTransaction::factory()->for($this->user)->inactive()->create([
        'account_id' => $this->account->id,
        'amount' => 50000,
        'direction' => TransactionDirection::Debit,
        'frequency' => RecurrenceFrequency::DontRepeat,
        'start_date' => '2026-06-10',
    ]);

    $tx = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => -50000,
        'post_date' => '2026-06-10',
        'planned_transaction_id' => null,
    ]);

    expect($this->matcher->matchForUser($this->user))->toBe(0)
        ->and($tx->fresh()->planned_transaction_id)->toBeNull();
});

test('is idempotent: a second run links nothing new and leaves existing links intact', function () {
    $plan = activeRentPlan($this->user, $this->account, '2026-06-05');
    $plan->update(['frequency' => RecurrenceFrequency::EveryWeek]);

    $alreadyMatched = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => -50000,
        'post_date' => '2026-06-05',
        'planned_transaction_id' => $plan->id,
    ]);

    $fresh = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => -50000,
        'post_date' => '2026-06-12',
        'planned_transaction_id' => null,
    ]);

    expect($this->matcher->matchForUser($this->user))->toBe(1)
        ->and($this->matcher->matchForUser($this->user))->toBe(0)
        ->and($alreadyMatched->fresh()->planned_transaction_id)->toBe($plan->id)
        ->and($fresh->fresh()->planned_transaction_id)->toBe($plan->id);
});

test('skips ambiguous matches when two transactions tie for nearest', function () {
    activeRentPlan($this->user, $this->account);

    $a = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => -50000,
        'post_date' => '2026-06-09',
        'planned_transaction_id' => null,
    ]);
    $b = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => -50000,
        'post_date' => '2026-06-11',
        'planned_transaction_id' => null,
    ]);

    expect($this->matcher->matchForUser($this->user))->toBe(0)
        ->and($a->fresh()->planned_transaction_id)->toBeNull()
        ->and($b->fresh()->planned_transaction_id)->toBeNull();
});

test('never links one transaction to two plans', function () {
    $planA = activeRentPlan($this->user, $this->account);
    $planB = activeRentPlan($this->user, $this->account);

    $tx = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => -50000,
        'post_date' => '2026-06-10',
        'planned_transaction_id' => null,
    ]);

    expect($this->matcher->matchForUser($this->user))->toBe(1)
        ->and($tx->fresh()->planned_transaction_id)->toBe(min($planA->id, $planB->id));
});

test('does not reach back past the lookback window', function () {
    activeRentPlan($this->user, $this->account, '2026-04-20');

    $tx = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => -50000,
        'post_date' => '2026-04-20',
        'planned_transaction_id' => null,
    ]);

    expect($this->matcher->matchForUser($this->user))->toBe(0)
        ->and($tx->fresh()->planned_transaction_id)->toBeNull();
});
