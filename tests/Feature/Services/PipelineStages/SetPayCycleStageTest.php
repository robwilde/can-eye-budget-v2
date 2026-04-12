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
use App\Services\PipelineStages\SetPayCycleStage;
use App\Services\TransactionAnalysisPipeline;
use Carbon\CarbonImmutable;

// ──────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────

function createPrimaryAccountSuggestion(PipelineRun $run, User $user, array $payloadOverrides = []): AnalysisSuggestion
{
    return AnalysisSuggestion::factory()
        ->primaryAccount()
        ->create([
            'pipeline_run_id' => $run->id,
            'user_id' => $user->id,
            'payload' => array_merge([
                'account_id' => 1,
                'account_name' => 'Everyday Account',
                'income_amount' => 300_000,
                'income_frequency' => 'monthly',
                'income_description' => 'SALARY DEPOSIT',
                'confidence_score' => 0.85,
                'matched_transaction_ids' => [],
                'outbound_transfer_count' => 2,
            ], $payloadOverrides),
        ]);
}

function makePayCycleContext(User $user, bool $isFirstSync = true): PipelineContext
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

function createIncomeTransactionForPayCycle(User $user, Account $account, array $overrides = []): Transaction
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

function createSalaryGroupForPayCycle(
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
        $transactions[] = createIncomeTransactionForPayCycle($user, $account, [
            'description' => $description,
            'amount' => $amount,
            'post_date' => $startDate->addDays($i * $intervalDays),
        ]);
    }

    return $transactions;
}

beforeEach(function () {
    $this->stage = new SetPayCycleStage;
    $this->user = User::factory()->create([
        'pay_amount' => null,
        'pay_frequency' => null,
        'next_pay_date' => null,
    ]);
    $this->account = Account::factory()->for($this->user)->create();
});

// ──────────────────────────────────────────────
// shouldRun Guard
// ──────────────────────────────────────────────

test('skips when not first sync', function () {
    $context = makePayCycleContext($this->user, isFirstSync: false);

    expect($this->stage->shouldRun($context))->toBeFalse();
});

test('skips when pay cycle already configured', function () {
    $this->user->update([
        'pay_amount' => 300_000,
        'pay_frequency' => 'monthly',
        'next_pay_date' => CarbonImmutable::now()->addDays(7),
    ]);
    $this->user->refresh();

    $context = makePayCycleContext($this->user);

    expect($this->stage->shouldRun($context))->toBeFalse();
});

test('runs when first sync and no pay cycle configured', function () {
    $context = makePayCycleContext($this->user);

    expect($this->stage->shouldRun($context))->toBeTrue();
});

// ──────────────────────────────────────────────
// Contract
// ──────────────────────────────────────────────

test('key returns expected string', function () {
    expect($this->stage->key())->toBe('set-pay-cycle');
});

test('label returns human-readable string', function () {
    expect($this->stage->label())->toBe('Set Pay Cycle');
});

// ──────────────────────────────────────────────
// Next Pay Date Calculation
// ──────────────────────────────────────────────

test('calculates next pay date for weekly frequency', function () {
    $mostRecentDate = CarbonImmutable::now()->subDays(3);
    $transactions = [];
    for ($i = 0; $i < 4; $i++) {
        $transactions[] = createIncomeTransactionForPayCycle($this->user, $this->account, [
            'description' => 'WEEKLY PAY',
            'amount' => 150_000,
            'post_date' => $mostRecentDate->subWeeks(3 - $i),
        ]);
    }
    $transactionIds = collect($transactions)->pluck('id')->all();

    $context = makePayCycleContext($this->user);
    createPrimaryAccountSuggestion($context->pipelineRun, $this->user, [
        'account_id' => $this->account->id,
        'income_frequency' => 'weekly',
        'income_amount' => 150_000,
        'income_description' => 'WEEKLY PAY',
        'matched_transaction_ids' => $transactionIds,
    ]);

    $result = $this->stage->execute($context);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    $nextPayDate = CarbonImmutable::parse($suggestion->payload['next_pay_date']);

    expect($nextPayDate->greaterThan(CarbonImmutable::today()))->toBeTrue()
        ->and($nextPayDate->diffInDays($mostRecentDate))->toBeLessThanOrEqual(7);
});

test('calculates next pay date for fortnightly frequency', function () {
    $mostRecentDate = CarbonImmutable::now()->subDays(5);
    $transactions = [];
    for ($i = 0; $i < 4; $i++) {
        $transactions[] = createIncomeTransactionForPayCycle($this->user, $this->account, [
            'description' => 'FORTNIGHTLY PAY',
            'amount' => 200_000,
            'post_date' => $mostRecentDate->subWeeks((3 - $i) * 2),
        ]);
    }
    $transactionIds = collect($transactions)->pluck('id')->all();

    $context = makePayCycleContext($this->user);
    createPrimaryAccountSuggestion($context->pipelineRun, $this->user, [
        'account_id' => $this->account->id,
        'income_frequency' => 'fortnightly',
        'income_amount' => 200_000,
        'income_description' => 'FORTNIGHTLY PAY',
        'matched_transaction_ids' => $transactionIds,
    ]);

    $result = $this->stage->execute($context);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    $nextPayDate = CarbonImmutable::parse($suggestion->payload['next_pay_date']);

    expect($nextPayDate->greaterThan(CarbonImmutable::today()))->toBeTrue()
        ->and($nextPayDate->diffInDays($mostRecentDate))->toBeLessThanOrEqual(14);
});

test('calculates next pay date for monthly frequency', function () {
    $mostRecentDate = CarbonImmutable::now()->subDays(10);
    $transactions = [];
    for ($i = 0; $i < 4; $i++) {
        $transactions[] = createIncomeTransactionForPayCycle($this->user, $this->account, [
            'description' => 'SALARY DEPOSIT',
            'amount' => 300_000,
            'post_date' => $mostRecentDate->subMonths(3 - $i),
        ]);
    }
    $transactionIds = collect($transactions)->pluck('id')->all();

    $context = makePayCycleContext($this->user);
    createPrimaryAccountSuggestion($context->pipelineRun, $this->user, [
        'account_id' => $this->account->id,
        'income_frequency' => 'monthly',
        'income_amount' => 300_000,
        'income_description' => 'SALARY DEPOSIT',
        'matched_transaction_ids' => $transactionIds,
    ]);

    $result = $this->stage->execute($context);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    $nextPayDate = CarbonImmutable::parse($suggestion->payload['next_pay_date']);

    expect($nextPayDate->greaterThan(CarbonImmutable::today()))->toBeTrue();
});

test('advances next pay date past today when computed date is in the past', function () {
    $oldDate = CarbonImmutable::now()->subDays(20);
    $transactions = [];
    for ($i = 0; $i < 3; $i++) {
        $transactions[] = createIncomeTransactionForPayCycle($this->user, $this->account, [
            'description' => 'WEEKLY PAY',
            'amount' => 150_000,
            'post_date' => $oldDate->subWeeks(2 - $i),
        ]);
    }
    $transactionIds = collect($transactions)->pluck('id')->all();

    $context = makePayCycleContext($this->user);
    createPrimaryAccountSuggestion($context->pipelineRun, $this->user, [
        'account_id' => $this->account->id,
        'income_frequency' => 'weekly',
        'income_amount' => 150_000,
        'income_description' => 'WEEKLY PAY',
        'matched_transaction_ids' => $transactionIds,
    ]);

    $result = $this->stage->execute($context);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    $nextPayDate = CarbonImmutable::parse($suggestion->payload['next_pay_date']);

    expect($nextPayDate->greaterThan(CarbonImmutable::today()))->toBeTrue();
});

test('advances multiple intervals if needed to reach the future', function () {
    $veryOldDate = CarbonImmutable::now()->subMonths(3);
    $transactions = [];
    for ($i = 0; $i < 3; $i++) {
        $transactions[] = createIncomeTransactionForPayCycle($this->user, $this->account, [
            'description' => 'SALARY DEPOSIT',
            'amount' => 300_000,
            'post_date' => $veryOldDate->subMonths(2 - $i),
        ]);
    }
    $transactionIds = collect($transactions)->pluck('id')->all();

    $context = makePayCycleContext($this->user);
    createPrimaryAccountSuggestion($context->pipelineRun, $this->user, [
        'account_id' => $this->account->id,
        'income_frequency' => 'monthly',
        'income_amount' => 300_000,
        'income_description' => 'SALARY DEPOSIT',
        'matched_transaction_ids' => $transactionIds,
    ]);

    $result = $this->stage->execute($context);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    $nextPayDate = CarbonImmutable::parse($suggestion->payload['next_pay_date']);

    expect($nextPayDate->greaterThan(CarbonImmutable::today()))->toBeTrue();
});

// ──────────────────────────────────────────────
// Stage 1 Dependency
// ──────────────────────────────────────────────

test('returns empty result when no primary account suggestion exists for this run', function () {
    $context = makePayCycleContext($this->user);

    $result = $this->stage->execute($context);

    expect($result)
        ->success->toBeTrue()
        ->suggestionIds->toBeEmpty();
});

test('creates audit entry when no primary account suggestion found', function () {
    $context = makePayCycleContext($this->user);

    $this->stage->execute($context);

    $audit = PipelineAuditEntry::where('pipeline_run_id', $context->pipelineRun->id)
        ->where('stage', 'set-pay-cycle')
        ->first();

    expect($audit)
        ->action->toBe('no_primary_account_suggestion');
});

test('returns empty result with audit entry when matched transactions no longer exist', function () {
    $context = makePayCycleContext($this->user);
    createPrimaryAccountSuggestion($context->pipelineRun, $this->user, [
        'account_id' => $this->account->id,
        'matched_transaction_ids' => [99999, 99998],
    ]);

    $result = $this->stage->execute($context);

    expect($result)
        ->success->toBeTrue()
        ->suggestionIds->toBeEmpty();

    $audit = PipelineAuditEntry::where('pipeline_run_id', $context->pipelineRun->id)
        ->where('stage', 'set-pay-cycle')
        ->where('action', 'no_matched_transactions_found')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->metadata['expected_ids'])->toBe([99999, 99998]);
});

// ──────────────────────────────────────────────
// Payload
// ──────────────────────────────────────────────

test('payload contains all required fields with correct types', function () {
    $transactions = [];
    for ($i = 0; $i < 3; $i++) {
        $transactions[] = createIncomeTransactionForPayCycle($this->user, $this->account, [
            'description' => 'SALARY DEPOSIT',
            'amount' => 300_000,
            'post_date' => CarbonImmutable::now()->subMonths(3 - $i),
        ]);
    }
    $transactionIds = collect($transactions)->pluck('id')->all();

    $context = makePayCycleContext($this->user);
    createPrimaryAccountSuggestion($context->pipelineRun, $this->user, [
        'account_id' => $this->account->id,
        'income_frequency' => 'monthly',
        'income_amount' => 300_000,
        'income_description' => 'SALARY DEPOSIT',
        'matched_transaction_ids' => $transactionIds,
    ]);

    $result = $this->stage->execute($context);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    $payload = $suggestion->payload;

    expect($payload)
        ->toHaveKeys([
            'pay_amount',
            'pay_frequency',
            'next_pay_date',
            'source_account_id',
            'source_description',
            'detected_dates',
        ])
        ->and($payload['pay_amount'])->toBeInt()
        ->and($payload['pay_frequency'])->toBeString()
        ->and($payload['next_pay_date'])->toBeString()
        ->and($payload['source_account_id'])->toBeInt()
        ->and($payload['source_description'])->toBeString()
        ->and($payload['detected_dates'])->toBeArray();
});

test('detected dates are sorted chronologically', function () {
    $baseDate = CarbonImmutable::now()->subMonths(3);
    $transactions = [];
    for ($i = 0; $i < 4; $i++) {
        $transactions[] = createIncomeTransactionForPayCycle($this->user, $this->account, [
            'description' => 'SALARY DEPOSIT',
            'amount' => 300_000,
            'post_date' => $baseDate->addMonths($i),
        ]);
    }
    $transactionIds = collect($transactions)->pluck('id')->all();

    $context = makePayCycleContext($this->user);
    createPrimaryAccountSuggestion($context->pipelineRun, $this->user, [
        'account_id' => $this->account->id,
        'income_frequency' => 'monthly',
        'income_amount' => 300_000,
        'income_description' => 'SALARY DEPOSIT',
        'matched_transaction_ids' => $transactionIds,
    ]);

    $result = $this->stage->execute($context);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    $detectedDates = $suggestion->payload['detected_dates'];

    $sorted = $detectedDates;
    sort($sorted);

    expect($detectedDates)->toBe($sorted)
        ->and($detectedDates)->toHaveCount(4);
});

test('pay frequency maps correctly from Stage 1 string to PayFrequency enum value', function () {
    $frequencies = ['weekly', 'fortnightly', 'monthly'];

    foreach ($frequencies as $frequency) {
        $transactions = [];
        for ($i = 0; $i < 3; $i++) {
            $transactions[] = createIncomeTransactionForPayCycle($this->user, $this->account, [
                'description' => 'PAY '.$frequency,
                'amount' => 200_000,
                'post_date' => CarbonImmutable::now()->subDays(($i + 1) * 7),
            ]);
        }
        $transactionIds = collect($transactions)->pluck('id')->all();

        $context = makePayCycleContext($this->user);
        createPrimaryAccountSuggestion($context->pipelineRun, $this->user, [
            'account_id' => $this->account->id,
            'income_frequency' => $frequency,
            'income_amount' => 200_000,
            'income_description' => 'PAY '.$frequency,
            'matched_transaction_ids' => $transactionIds,
        ]);

        $result = $this->stage->execute($context);

        $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
        expect($suggestion->payload['pay_frequency'])->toBe($frequency);
    }
});

// ──────────────────────────────────────────────
// Integration
// ──────────────────────────────────────────────

test('stage registered in pipeline and produces PayCycle suggestion on first sync', function () {
    createSalaryGroupForPayCycle($this->user, $this->account, 'SALARY DEPOSIT', 300_000, 4, 30);

    $pipeline = app(TransactionAnalysisPipeline::class);
    $run = $pipeline->run($this->user, PipelineTrigger::Sync);

    expect($run->stages_completed)->toContain('identify-primary-account')
        ->and($run->stages_completed)->toContain('set-pay-cycle');

    $payCycleSuggestion = AnalysisSuggestion::where('pipeline_run_id', $run->id)
        ->where('type', SuggestionType::PayCycle)
        ->first();

    expect($payCycleSuggestion)->not->toBeNull()
        ->and($payCycleSuggestion->payload['pay_amount'])->toBe(300_000)
        ->and($payCycleSuggestion->payload['pay_frequency'])->toBe('monthly')
        ->and($payCycleSuggestion->payload['source_account_id'])->toBe($this->account->id);

    $primaryAccountSuggestion = AnalysisSuggestion::where('pipeline_run_id', $run->id)
        ->where('type', SuggestionType::PrimaryAccount)
        ->first();

    expect($primaryAccountSuggestion)->not->toBeNull();
});

test('stage skipped when user already has pay cycle configured', function () {
    $this->user->update([
        'pay_amount' => 300_000,
        'pay_frequency' => 'monthly',
        'next_pay_date' => CarbonImmutable::now()->addDays(7),
        'primary_account_id' => $this->account->id,
    ]);
    $this->user->refresh();

    createSalaryGroupForPayCycle($this->user, $this->account, 'SALARY DEPOSIT', 300_000, 4, 30);

    $pipeline = app(TransactionAnalysisPipeline::class);
    $run = $pipeline->run($this->user, PipelineTrigger::Sync);

    expect($run->stages_skipped)->toContain('set-pay-cycle');

    $payCycleSuggestion = AnalysisSuggestion::where('pipeline_run_id', $run->id)
        ->where('type', SuggestionType::PayCycle)
        ->first();

    expect($payCycleSuggestion)->toBeNull();
});
