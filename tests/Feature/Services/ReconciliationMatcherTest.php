<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReconciliationMatcher;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->matcher = app(ReconciliationMatcher::class);
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

// ── findSuggestions ──────────────────────────────────────────────

test('finds matching transaction on same account with similar amount and nearby date', function () {
    $planned = PlannedTransaction::factory()->for($this->user)->for($this->account)->noRepeat()->create([
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-03-15',
    ]);

    Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => 10000,
        'post_date' => '2026-03-15',
    ]);

    $suggestions = $this->matcher->findSuggestions($planned, CarbonImmutable::parse('2026-03-15'));

    expect($suggestions)->toHaveCount(1);
});

test('finds transaction within amount tolerance of 10 percent', function () {
    $planned = PlannedTransaction::factory()->for($this->user)->for($this->account)->noRepeat()->create([
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-03-15',
    ]);

    Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => 10900,
        'post_date' => '2026-03-15',
    ]);

    $suggestions = $this->matcher->findSuggestions($planned, CarbonImmutable::parse('2026-03-15'));

    expect($suggestions)->toHaveCount(1);
});

test('ignores transaction outside amount tolerance', function () {
    $planned = PlannedTransaction::factory()->for($this->user)->for($this->account)->noRepeat()->create([
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-03-15',
    ]);

    Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => 11100,
        'post_date' => '2026-03-15',
    ]);

    $suggestions = $this->matcher->findSuggestions($planned, CarbonImmutable::parse('2026-03-15'));

    expect($suggestions)->toBeEmpty();
});

test('finds transaction within date tolerance of 3 days', function () {
    $planned = PlannedTransaction::factory()->for($this->user)->for($this->account)->noRepeat()->create([
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-03-15',
    ]);

    Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => 10000,
        'post_date' => '2026-03-18',
    ]);

    $suggestions = $this->matcher->findSuggestions($planned, CarbonImmutable::parse('2026-03-15'));

    expect($suggestions)->toHaveCount(1);
});

test('ignores transaction outside date tolerance', function () {
    $planned = PlannedTransaction::factory()->for($this->user)->for($this->account)->noRepeat()->create([
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-03-15',
    ]);

    Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => 10000,
        'post_date' => '2026-03-19',
    ]);

    $suggestions = $this->matcher->findSuggestions($planned, CarbonImmutable::parse('2026-03-15'));

    expect($suggestions)->toBeEmpty();
});

test('ignores transactions on different account', function () {
    $otherAccount = Account::factory()->for($this->user)->create();

    $planned = PlannedTransaction::factory()->for($this->user)->for($this->account)->noRepeat()->create([
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-03-15',
    ]);

    Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $otherAccount->id,
        'amount' => 10000,
        'post_date' => '2026-03-15',
    ]);

    $suggestions = $this->matcher->findSuggestions($planned, CarbonImmutable::parse('2026-03-15'));

    expect($suggestions)->toBeEmpty();
});

test('ignores already linked transactions', function () {
    $planned = PlannedTransaction::factory()->for($this->user)->for($this->account)->noRepeat()->create([
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-03-15',
    ]);

    $otherPlanned = PlannedTransaction::factory()->for($this->user)->for($this->account)->noRepeat()->create();

    Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => 10000,
        'post_date' => '2026-03-15',
        'planned_transaction_id' => $otherPlanned->id,
    ]);

    $suggestions = $this->matcher->findSuggestions($planned, CarbonImmutable::parse('2026-03-15'));

    expect($suggestions)->toBeEmpty();
});

test('ignores transactions with wrong direction', function () {
    $planned = PlannedTransaction::factory()->for($this->user)->for($this->account)->noRepeat()->create([
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-03-15',
    ]);

    Transaction::factory()->for($this->user)->credit()->create([
        'account_id' => $this->account->id,
        'amount' => 10000,
        'post_date' => '2026-03-15',
    ]);

    $suggestions = $this->matcher->findSuggestions($planned, CarbonImmutable::parse('2026-03-15'));

    expect($suggestions)->toBeEmpty();
});

test('returns results ordered by date proximity then amount proximity', function () {
    $planned = PlannedTransaction::factory()->for($this->user)->for($this->account)->noRepeat()->create([
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-03-15',
    ]);

    $farDate = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => 10000,
        'post_date' => '2026-03-17',
    ]);

    $exactDate = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => 10500,
        'post_date' => '2026-03-15',
    ]);

    $suggestions = $this->matcher->findSuggestions($planned, CarbonImmutable::parse('2026-03-15'));

    expect($suggestions)->toHaveCount(2)
        ->and($suggestions->first()->id)->toBe($exactDate->id)
        ->and($suggestions->last()->id)->toBe($farDate->id);
});

test('finds and correctly sorts transactions with negative amounts', function () {
    $planned = PlannedTransaction::factory()->for($this->user)->for($this->account)->noRepeat()->create([
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-03-15',
    ]);

    $exactNegative = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => -10000,
        'post_date' => '2026-03-15',
    ]);

    $closeNegative = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => -10500,
        'post_date' => '2026-03-15',
    ]);

    $suggestions = $this->matcher->findSuggestions($planned, CarbonImmutable::parse('2026-03-15'));

    expect($suggestions)->toHaveCount(2)
        ->and($suggestions->first()->id)->toBe($exactNegative->id)
        ->and($suggestions->last()->id)->toBe($closeNegative->id);
});

test('includes transactions from other users accounts are excluded', function () {
    $otherUser = User::factory()->create();
    $otherAccount = Account::factory()->for($otherUser)->create();

    $planned = PlannedTransaction::factory()->for($this->user)->for($this->account)->noRepeat()->create([
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-03-15',
    ]);

    Transaction::factory()->for($otherUser)->debit()->create([
        'account_id' => $otherAccount->id,
        'amount' => 10000,
        'post_date' => '2026-03-15',
    ]);

    $suggestions = $this->matcher->findSuggestions($planned, CarbonImmutable::parse('2026-03-15'));

    expect($suggestions)->toBeEmpty();
});

// ── link ─────────────────────────────────────────────────────────

test('link sets planned_transaction_id on the transaction', function () {
    $planned = PlannedTransaction::factory()->for($this->user)->for($this->account)->noRepeat()->create();

    $transaction = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
    ]);

    $this->matcher->link($transaction, $planned);

    expect($transaction->fresh()->planned_transaction_id)->toBe($planned->id);
});

test('link overwrites existing planned_transaction_id', function () {
    $planned = PlannedTransaction::factory()->for($this->user)->for($this->account)->noRepeat()->create();
    $otherPlanned = PlannedTransaction::factory()->for($this->user)->for($this->account)->noRepeat()->create();

    $transaction = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'planned_transaction_id' => $otherPlanned->id,
    ]);

    $this->matcher->link($transaction, $planned);

    expect($transaction->fresh()->planned_transaction_id)->toBe($planned->id);
});

// ── unlink ───────────────────────────────────────────────────────

test('unlink clears planned_transaction_id on the transaction', function () {
    $planned = PlannedTransaction::factory()->for($this->user)->for($this->account)->noRepeat()->create();

    $transaction = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'planned_transaction_id' => $planned->id,
    ]);

    $this->matcher->unlink($transaction);

    expect($transaction->fresh()->planned_transaction_id)->toBeNull();
});

test('unlink on already unlinked transaction does nothing', function () {
    $transaction = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'planned_transaction_id' => null,
    ]);

    $this->matcher->unlink($transaction);

    expect($transaction->fresh()->planned_transaction_id)->toBeNull();
});

// ── findLinkedForOccurrence ──────────────────────────────────────

test('findLinkedForOccurrence returns linked transaction near occurrence date', function () {
    $planned = PlannedTransaction::factory()->for($this->user)->for($this->account)->noRepeat()->create([
        'start_date' => '2026-03-15',
    ]);

    $linked = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'post_date' => '2026-03-15',
        'planned_transaction_id' => $planned->id,
    ]);

    $result = $this->matcher->findLinkedForOccurrence($planned, CarbonImmutable::parse('2026-03-15'));

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($linked->id);
});

test('findLinkedForOccurrence returns null when linked transaction is outside date tolerance', function () {
    $planned = PlannedTransaction::factory()->for($this->user)->for($this->account)->noRepeat()->create([
        'start_date' => '2026-03-15',
    ]);

    Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'post_date' => '2026-04-15',
        'planned_transaction_id' => $planned->id,
    ]);

    $result = $this->matcher->findLinkedForOccurrence($planned, CarbonImmutable::parse('2026-03-15'));

    expect($result)->toBeNull();
});
