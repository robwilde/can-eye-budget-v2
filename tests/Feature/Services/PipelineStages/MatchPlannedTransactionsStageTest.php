<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\PipelineContext;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\PipelineRun;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PipelineStages\MatchPlannedTransactionsStage;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::create(2026, 6, 15));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->pipelineRun = PipelineRun::factory()->for($this->user)->create();
    $this->context = new PipelineContext(user: $this->user, pipelineRun: $this->pipelineRun, isFirstSync: false);
    $this->stage = app(MatchPlannedTransactionsStage::class);
});

test('exposes its contract metadata', function () {
    expect($this->stage->key())->toBe('match-planned-transactions')
        ->and($this->stage->label())->toBeString()
        ->and($this->stage->shouldRun($this->context))->toBeTrue();
});

test('tags a matching transaction and returns a successful result', function () {
    $plan = PlannedTransaction::factory()->for($this->user)->create([
        'account_id' => $this->account->id,
        'amount' => 50000,
        'direction' => TransactionDirection::Debit,
        'frequency' => RecurrenceFrequency::DontRepeat,
        'start_date' => '2026-06-10',
        'is_active' => true,
    ]);

    $tx = Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $this->account->id,
        'amount' => -50000,
        'post_date' => '2026-06-10',
        'planned_transaction_id' => null,
    ]);

    $result = $this->stage->execute($this->context);

    expect($result->success)->toBeTrue()
        ->and($result->stage)->toBe('match-planned-transactions')
        ->and($tx->fresh()->planned_transaction_id)->toBe($plan->id);
});
