<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\PipelineContext;
use App\Enums\PipelineTrigger;
use App\Enums\SuggestionType;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineAuditEntry;
use App\Models\PipelineRun;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PipelineStages\IdentifyPrimaryAccountStage;
use App\Services\TransactionAnalysisPipeline;
use Carbon\CarbonImmutable;

// ──────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────

function createIncomeTransaction(User $user, Account $account, array $overrides = []): Transaction
{
    return Transaction::factory()
        ->for($user)
        ->for($account)
        ->credit()
        ->fromBasiq()
        ->create(array_merge([
            'transfer_pair_id' => null,
            'merchant_name' => null,
            'clean_description' => null,
        ], $overrides));
}

function createSalaryGroup(
    User $user,
    Account $account,
    string $description,
    int $amount,
    int $count,
    int $intervalDays,
    ?CarbonImmutable $startDate = null,
): array {
    $startDate ??= CarbonImmutable::parse('2025-06-01');
    $transactions = [];

    for ($i = 0; $i < $count; $i++) {
        $transactions[] = createIncomeTransaction($user, $account, [
            'description' => $description,
            'amount' => $amount,
            'post_date' => $startDate->addDays($i * $intervalDays),
        ]);
    }

    return $transactions;
}

function makeContext(User $user, bool $isFirstSync = true): PipelineContext
{
    $pipelineRun = PipelineRun::factory()->for($user)->create([
        'is_first_sync' => $isFirstSync,
    ]);

    return new PipelineContext(
        user: $user,
        pipelineRun: $pipelineRun,
        isFirstSync: $isFirstSync,
    );
}

beforeEach(function () {
    $this->stage = new IdentifyPrimaryAccountStage;
    $this->user = User::factory()->create(['primary_account_id' => null]);
    $this->account = Account::factory()->for($this->user)->create();
});

// ──────────────────────────────────────────────
// Detection
// ──────────────────────────────────────────────

test('detects primary account with consistent salary', function () {
    createSalaryGroup($this->user, $this->account, 'SALARY DEPOSIT', 300_000, 4, 30);
    $context = makeContext($this->user);

    $result = $this->stage->execute($context);

    expect($result)
        ->success->toBeTrue()
        ->suggestionIds->toHaveCount(1);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    expect($suggestion)
        ->type->toBe(SuggestionType::PrimaryAccount)
        ->and($suggestion->payload['account_id'])->toBe($this->account->id)
        ->and($suggestion->payload['income_frequency'])->toBe('monthly');
});

test('picks strongest income source when multiple exist', function () {
    createSalaryGroup($this->user, $this->account, 'SALARY DEPOSIT', 300_000, 4, 30);
    createSalaryGroup($this->user, $this->account, 'SMALL REFUND', 5_000, 4, 30);

    $context = makeContext($this->user);
    $result = $this->stage->execute($context);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    expect($suggestion->payload['income_description'])->toBe('SALARY DEPOSIT')
        ->and($suggestion->payload['income_amount'])->toBe(300_000);
});

test('picks strongest across accounts', function () {
    $transactionAccount = $this->account;
    $savingsAccount = Account::factory()->savings()->for($this->user)->create();

    createSalaryGroup($this->user, $transactionAccount, 'SALARY DEPOSIT', 300_000, 5, 14);
    createSalaryGroup($this->user, $savingsAccount, 'INTEREST PAYMENT', 5_000, 3, 30);

    $context = makeContext($this->user);
    $result = $this->stage->execute($context);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    expect($suggestion->payload['account_id'])->toBe($transactionAccount->id);
});

test('creates no suggestion when no clear income pattern', function () {
    for ($i = 0; $i < 5; $i++) {
        createIncomeTransaction($this->user, $this->account, [
            'description' => 'RANDOM CREDIT '.$i,
            'amount' => fake()->numberBetween(1000, 100_000),
            'post_date' => CarbonImmutable::parse('2025-06-01')->addDays(fake()->numberBetween(1, 90)),
        ]);
    }

    $context = makeContext($this->user);
    $result = $this->stage->execute($context);

    expect($result)
        ->success->toBeTrue()
        ->suggestionIds->toBeEmpty();
});

test('requires minimum 2 credit transactions', function () {
    createIncomeTransaction($this->user, $this->account, [
        'description' => 'SALARY DEPOSIT',
        'amount' => 300_000,
        'post_date' => CarbonImmutable::parse('2025-06-01'),
    ]);

    $context = makeContext($this->user);
    $result = $this->stage->execute($context);

    expect($result)
        ->success->toBeTrue()
        ->suggestionIds->toBeEmpty();
});

// ──────────────────────────────────────────────
// Frequency
// ──────────────────────────────────────────────

test('detects weekly income frequency', function () {
    createSalaryGroup($this->user, $this->account, 'WEEKLY PAY', 150_000, 4, 7);

    $context = makeContext($this->user);
    $result = $this->stage->execute($context);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    expect($suggestion->payload['income_frequency'])->toBe('weekly');
});

test('detects fortnightly income frequency', function () {
    createSalaryGroup($this->user, $this->account, 'SALARY DEPOSIT', 300_000, 4, 14);

    $context = makeContext($this->user);
    $result = $this->stage->execute($context);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    expect($suggestion->payload['income_frequency'])->toBe('fortnightly');
});

test('detects monthly income frequency', function () {
    createSalaryGroup($this->user, $this->account, 'SALARY DEPOSIT', 300_000, 4, 30);

    $context = makeContext($this->user);
    $result = $this->stage->execute($context);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    expect($suggestion->payload['income_frequency'])->toBe('monthly');
});

test('rejects income group with interval CV above 0.3', function () {
    $startDate = CarbonImmutable::parse('2025-06-01');
    createIncomeTransaction($this->user, $this->account, [
        'description' => 'IRREGULAR PAY',
        'amount' => 300_000,
        'post_date' => $startDate,
    ]);
    createIncomeTransaction($this->user, $this->account, [
        'description' => 'IRREGULAR PAY',
        'amount' => 300_000,
        'post_date' => $startDate->addDays(30),
    ]);
    createIncomeTransaction($this->user, $this->account, [
        'description' => 'IRREGULAR PAY',
        'amount' => 300_000,
        'post_date' => $startDate->addDays(45),
    ]);
    createIncomeTransaction($this->user, $this->account, [
        'description' => 'IRREGULAR PAY',
        'amount' => 300_000,
        'post_date' => $startDate->addDays(90),
    ]);

    $context = makeContext($this->user);
    $result = $this->stage->execute($context);

    expect($result->suggestionIds)->toBeEmpty();
});

// ──────────────────────────────────────────────
// Transfer Signal
// ──────────────────────────────────────────────

test('outbound transfers boost confidence', function () {
    createSalaryGroup($this->user, $this->account, 'SALARY DEPOSIT', 300_000, 4, 30);

    $contextNoTransfers = makeContext($this->user);
    $resultNoTransfers = $this->stage->execute($contextNoTransfers);
    $scoreWithout = AnalysisSuggestion::find($resultNoTransfers->suggestionIds[0])->payload['confidence_score'];

    AnalysisSuggestion::query()->delete();

    $savingsAccount = Account::factory()->savings()->for($this->user)->create();
    for ($i = 0; $i < 5; $i++) {
        $creditSide = Transaction::factory()
            ->for($this->user)
            ->for($savingsAccount)
            ->credit()
            ->fromBasiq()
            ->create(['post_date' => CarbonImmutable::parse('2025-06-05')->addDays($i * 30)]);

        Transaction::factory()
            ->for($this->user)
            ->for($this->account)
            ->debit()
            ->fromBasiq()
            ->create([
                'transfer_pair_id' => $creditSide->id,
                'post_date' => CarbonImmutable::parse('2025-06-05')->addDays($i * 30),
            ]);
    }

    $contextWithTransfers = makeContext($this->user);
    $resultWithTransfers = $this->stage->execute($contextWithTransfers);

    $suggestionWith = AnalysisSuggestion::find($resultWithTransfers->suggestionIds[0]);
    $scoreWith = $suggestionWith->payload['confidence_score'];

    expect($scoreWith)->toBeGreaterThan($scoreWithout)
        ->and($suggestionWith->payload['outbound_transfer_count'])->toBe(5);
});

// ──────────────────────────────────────────────
// shouldRun Guard
// ──────────────────────────────────────────────

test('skips when not first sync', function () {
    $context = makeContext($this->user, isFirstSync: false);

    expect($this->stage->shouldRun($context))->toBeFalse();
});

test('skips when primary account already set', function () {
    $this->user->update(['primary_account_id' => $this->account->id]);
    $this->user->refresh();

    $context = makeContext($this->user);

    expect($this->stage->shouldRun($context))->toBeFalse();
});

test('runs when first sync and no primary account', function () {
    $context = makeContext($this->user);

    expect($this->stage->shouldRun($context))->toBeTrue();
});

// ──────────────────────────────────────────────
// Account Filtering
// ──────────────────────────────────────────────

test('only considers Transaction and Savings account types', function () {
    $creditCardAccount = Account::factory()->creditCard()->for($this->user)->create();
    createSalaryGroup($this->user, $creditCardAccount, 'SALARY DEPOSIT', 300_000, 4, 30);

    $context = makeContext($this->user);
    $result = $this->stage->execute($context);

    expect($result->suggestionIds)->toBeEmpty();
});

test('only considers active accounts', function () {
    $inactiveAccount = Account::factory()->inactive()->for($this->user)->create();
    createSalaryGroup($this->user, $inactiveAccount, 'SALARY DEPOSIT', 300_000, 4, 30);

    $context = makeContext($this->user);
    $result = $this->stage->execute($context);

    expect($result->suggestionIds)->toBeEmpty();
});

test('excludes incoming transfers from income grouping', function () {
    $otherAccount = Account::factory()->for($this->user)->create();

    $startDate = CarbonImmutable::parse('2025-06-01');

    for ($i = 0; $i < 4; $i++) {
        $debitSide = Transaction::factory()
            ->for($this->user)
            ->for($otherAccount)
            ->debit()
            ->fromBasiq()
            ->create(['post_date' => $startDate->addDays($i * 30)]);

        Transaction::factory()
            ->for($this->user)
            ->for($this->account)
            ->credit()
            ->fromBasiq()
            ->create([
                'description' => 'TRANSFER FROM OTHER',
                'amount' => 300_000,
                'transfer_pair_id' => $debitSide->id,
                'post_date' => $startDate->addDays($i * 30),
            ]);
    }

    $context = makeContext($this->user);
    $result = $this->stage->execute($context);

    expect($result->suggestionIds)->toBeEmpty();
});

// ──────────────────────────────────────────────
// Payload
// ──────────────────────────────────────────────

test('payload contains all required fields with correct types', function () {
    createSalaryGroup($this->user, $this->account, 'SALARY DEPOSIT', 300_000, 4, 30);

    $context = makeContext($this->user);
    $result = $this->stage->execute($context);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    $payload = $suggestion->payload;

    expect($payload)
        ->toHaveKeys([
            'account_id',
            'account_name',
            'income_amount',
            'income_frequency',
            'income_description',
            'confidence_score',
            'matched_transaction_ids',
            'outbound_transfer_count',
        ])
        ->and($payload['account_id'])->toBeInt()
        ->and($payload['account_name'])->toBeString()
        ->and($payload['income_amount'])->toBeInt()
        ->and($payload['income_frequency'])->toBeString()
        ->and($payload['income_description'])->toBeString()
        ->and($payload['confidence_score'])->toBeFloat()
        ->and($payload['matched_transaction_ids'])->toBeArray()
        ->and($payload['outbound_transfer_count'])->toBeInt();
});

test('matched_transaction_ids contains all group transaction IDs', function () {
    $transactions = createSalaryGroup($this->user, $this->account, 'SALARY DEPOSIT', 300_000, 4, 30);
    $expectedIds = collect($transactions)->pluck('id')->sort()->values()->all();

    $context = makeContext($this->user);
    $result = $this->stage->execute($context);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    $actualIds = collect($suggestion->payload['matched_transaction_ids'])->sort()->values()->all();

    expect($actualIds)->toBe($expectedIds);
});

// ──────────────────────────────────────────────
// Audit
// ──────────────────────────────────────────────

test('creates audit entry when no income pattern detected', function () {
    createIncomeTransaction($this->user, $this->account, [
        'description' => 'RANDOM THING',
        'amount' => 300_000,
        'post_date' => CarbonImmutable::parse('2025-06-01'),
    ]);

    $context = makeContext($this->user);
    $this->stage->execute($context);

    $audit = PipelineAuditEntry::where('pipeline_run_id', $context->pipelineRun->id)
        ->where('stage', 'identify-primary-account')
        ->first();

    expect($audit)
        ->action->toBe('no_income_pattern_detected');
});

// ──────────────────────────────────────────────
// Contract
// ──────────────────────────────────────────────

test('key returns expected string', function () {
    expect($this->stage->key())->toBe('identify-primary-account');
});

test('label returns human-readable string', function () {
    expect($this->stage->label())->toBe('Identify Primary Account');
});

// ──────────────────────────────────────────────
// Integration
// ──────────────────────────────────────────────

test('stage is registered in pipeline and full run produces suggestion on first sync', function () {
    createSalaryGroup($this->user, $this->account, 'SALARY DEPOSIT', 300_000, 4, 30);

    $pipeline = app(TransactionAnalysisPipeline::class);
    $run = $pipeline->run($this->user, PipelineTrigger::Sync);

    expect($run->stages_completed)->toContain('identify-primary-account');

    $suggestion = AnalysisSuggestion::where('pipeline_run_id', $run->id)
        ->where('type', SuggestionType::PrimaryAccount)
        ->first();

    expect($suggestion)->not->toBeNull()
        ->and($suggestion->payload['account_id'])->toBe($this->account->id);
});

test('stage is skipped in pipeline when not first sync', function () {
    $this->user->update(['primary_account_id' => $this->account->id]);
    $this->user->refresh();

    createSalaryGroup($this->user, $this->account, 'SALARY DEPOSIT', 300_000, 4, 30);

    $pipeline = app(TransactionAnalysisPipeline::class);
    $run = $pipeline->run($this->user, PipelineTrigger::Sync);

    expect($run->stages_skipped)->toContain('identify-primary-account');

    $suggestion = AnalysisSuggestion::where('pipeline_run_id', $run->id)
        ->where('type', SuggestionType::PrimaryAccount)
        ->first();

    expect($suggestion)->toBeNull();
});
