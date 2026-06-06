<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Events\TransactionEntered;
use App\Events\TransactionReconciled;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionIngestor;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->ingestor = app(TransactionIngestor::class);
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function ingestorRentPlan(User $user, Account $account, string $startDate = '2026-06-10'): PlannedTransaction
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

test('reconciles a transaction that fulfils an active plan occurrence', function () {
    Event::fake([TransactionReconciled::class, TransactionEntered::class]);
    $plan = ingestorRentPlan($this->user, $this->account);

    $tx = Transaction::factory()->for($this->user)->debit()->make([
        'account_id' => $this->account->id,
        'amount' => -49000,
        'post_date' => '2026-06-11',
        'planned_transaction_id' => null,
    ]);

    $result = $this->ingestor->ingest($tx);

    expect($result->planned_transaction_id)->toBe($plan->id);

    Event::assertDispatched(
        TransactionReconciled::class,
        fn (TransactionReconciled $e): bool => $e->transaction->is($tx) && $e->plannedTransactionId === $plan->id,
    );
    Event::assertNotDispatched(TransactionEntered::class);
});

test('leaves a transaction with no matching plan entered', function () {
    Event::fake([TransactionReconciled::class, TransactionEntered::class]);
    ingestorRentPlan($this->user, $this->account);

    $tx = Transaction::factory()->for($this->user)->debit()->make([
        'account_id' => $this->account->id,
        'amount' => -49000,
        'post_date' => '2026-06-30',
        'planned_transaction_id' => null,
    ]);

    $result = $this->ingestor->ingest($tx);

    expect($result->planned_transaction_id)->toBeNull();

    Event::assertDispatched(
        TransactionEntered::class,
        fn (TransactionEntered $e): bool => $e->transaction->is($tx),
    );
    Event::assertNotDispatched(TransactionReconciled::class);
});

test('persists an unsaved transaction passed to ingest', function () {
    $tx = Transaction::factory()->for($this->user)->debit()->make([
        'account_id' => $this->account->id,
        'amount' => -1200,
        'post_date' => '2026-06-11',
        'planned_transaction_id' => null,
    ]);

    expect($tx->exists)->toBeFalse();

    $this->ingestor->ingest($tx);

    expect($tx->exists)->toBeTrue()
        ->and(Transaction::query()->whereKey($tx->id)->exists())->toBeTrue();
});

test('does not relink a transaction already reconciled to a plan', function () {
    Event::fake([TransactionReconciled::class, TransactionEntered::class]);
    $plan = ingestorRentPlan($this->user, $this->account);

    $tx = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => -50000,
        'post_date' => '2026-06-10',
        'planned_transaction_id' => $plan->id,
    ]);

    $this->ingestor->ingest($tx);

    expect($tx->fresh()->planned_transaction_id)->toBe($plan->id);

    Event::assertNotDispatched(TransactionReconciled::class);
    Event::assertDispatched(TransactionEntered::class);
});
