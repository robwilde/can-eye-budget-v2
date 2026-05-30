<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\PipelineContext;
use App\Enums\PayFrequency;
use App\Enums\PipelineTrigger;
use App\Enums\SuggestionStatus;
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

/**
 * Seed a regular salary on an account: `count` credits spaced `intervalDays`
 * apart, the most recent landing `lastDaysAgo` days before today.
 */
function seedSalary(
    User $user,
    Account $account,
    int $amount = 300_000,
    int $intervalDays = 14,
    int $count = 4,
    int $lastDaysAgo = 3,
    string $description = 'SALARY DEPOSIT',
): void {
    $lastDate = CarbonImmutable::today()->subDays($lastDaysAgo);

    for ($i = 0; $i < $count; $i++) {
        Transaction::factory()
            ->for($user)
            ->for($account)
            ->credit()
            ->fromBasiq()
            ->create([
                'description' => $description,
                'merchant_name' => null,
                'clean_description' => null,
                'amount' => $amount,
                'post_date' => $lastDate->subDays($intervalDays * ($count - 1 - $i)),
                'transfer_pair_id' => null,
            ]);
    }
}

beforeEach(function () {
    $this->stage = app(SetPayCycleStage::class);
    $this->user = User::factory()->create([
        'pay_amount' => null,
        'pay_frequency' => null,
        'next_pay_date' => null,
        'primary_account_id' => null,
    ]);
    $this->account = Account::factory()->for($this->user)->create();
});

function withPrimaryAccount(User $user, Account $account): User
{
    $user->update(['primary_account_id' => $account->id]);
    $user->refresh();

    return $user;
}

// ──────────────────────────────────────────────
// shouldRun Guard
// ──────────────────────────────────────────────

test('skips when no primary account is set', function () {
    $context = makePayCycleContext($this->user);

    expect($this->stage->shouldRun($context))->toBeFalse();
});

test('runs whenever a primary account exists, even when not first sync', function () {
    withPrimaryAccount($this->user, $this->account);

    $context = makePayCycleContext($this->user, isFirstSync: false);

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
// No primary / no income
// ──────────────────────────────────────────────

test('audits no_primary_account when primary account is missing', function () {
    $context = makePayCycleContext($this->user);

    $result = $this->stage->execute($context);

    expect($result->success)->toBeTrue()
        ->and($result->suggestionIds)->toBeEmpty();

    $audit = PipelineAuditEntry::where('pipeline_run_id', $context->pipelineRun->id)
        ->where('stage', 'set-pay-cycle')
        ->where('action', 'no_primary_account')
        ->first();

    expect($audit)->not->toBeNull();
});

test('audits no_income_pattern_detected when primary account has no salary', function () {
    withPrimaryAccount($this->user, $this->account);

    $context = makePayCycleContext($this->user);
    $result = $this->stage->execute($context);

    expect($result->suggestionIds)->toBeEmpty();

    $audit = PipelineAuditEntry::where('pipeline_run_id', $context->pipelineRun->id)
        ->where('stage', 'set-pay-cycle')
        ->where('action', 'no_income_pattern_detected')
        ->first();

    expect($audit)->not->toBeNull();
});

// ──────────────────────────────────────────────
// Auto-apply (issue #249)
// ──────────────────────────────────────────────

test('auto-applies pay cycle when not configured and confidence is high', function () {
    withPrimaryAccount($this->user, $this->account);
    seedSalary($this->user, $this->account, amount: 300_000, intervalDays: 14);

    $context = makePayCycleContext($this->user);
    $result = $this->stage->execute($context);

    expect($result->suggestionIds)->toHaveCount(1);

    $user = $this->user->fresh();
    expect($user->pay_amount)->toBe(300_000)
        ->and($user->pay_frequency)->toBe(PayFrequency::Fortnightly)
        ->and($user->next_pay_date)->not->toBeNull();

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    expect($suggestion->status)->toBe(SuggestionStatus::Accepted);

    $audit = PipelineAuditEntry::where('pipeline_run_id', $context->pipelineRun->id)
        ->where('stage', 'set-pay-cycle')
        ->where('action', 'auto_applied')
        ->first();

    expect($audit)->not->toBeNull();
});

// ──────────────────────────────────────────────
// Never overwrite an existing pay cycle (issue #249)
// ──────────────────────────────────────────────

test('does not overwrite an existing pay cycle, raising a pending suggestion when the pattern changed', function () {
    $existingNextPay = CarbonImmutable::today()->addDays(5)->format('Y-m-d');
    $this->user->update([
        'pay_amount' => 250_000,
        'pay_frequency' => 'monthly',
        'next_pay_date' => $existingNextPay,
    ]);
    withPrimaryAccount($this->user, $this->account);

    // Detected pattern differs: fortnightly 300k.
    seedSalary($this->user, $this->account, amount: 300_000, intervalDays: 14);

    $context = makePayCycleContext($this->user, isFirstSync: false);
    $result = $this->stage->execute($context);

    // A suggestion is raised for review, but the user's values are untouched.
    expect($result->suggestionIds)->toHaveCount(1);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    expect($suggestion->status)->toBe(SuggestionStatus::Pending);

    $user = $this->user->fresh();
    expect($user->pay_amount)->toBe(250_000)
        ->and($user->pay_frequency)->toBe(PayFrequency::Monthly)
        ->and($user->next_pay_date->format('Y-m-d'))->toBe($existingNextPay);
});

test('records pay_cycle_unchanged and raises no suggestion when detection matches the existing cycle', function () {
    $this->user->update([
        'pay_amount' => 300_000,
        'pay_frequency' => 'fortnightly',
        'next_pay_date' => CarbonImmutable::today()->addDays(5)->format('Y-m-d'),
    ]);
    withPrimaryAccount($this->user, $this->account);

    seedSalary($this->user, $this->account, amount: 300_000, intervalDays: 14);

    $context = makePayCycleContext($this->user, isFirstSync: false);
    $result = $this->stage->execute($context);

    expect($result->suggestionIds)->toBeEmpty();

    $audit = PipelineAuditEntry::where('pipeline_run_id', $context->pipelineRun->id)
        ->where('stage', 'set-pay-cycle')
        ->where('action', 'pay_cycle_unchanged')
        ->first();

    expect($audit)->not->toBeNull();
});

// ──────────────────────────────────────────────
// Next Pay Date Calculation
// ──────────────────────────────────────────────

test('computes a future next pay date for each frequency', function (int $intervalDays, string $expectedFrequency) {
    withPrimaryAccount($this->user, $this->account);
    seedSalary($this->user, $this->account, intervalDays: $intervalDays, lastDaysAgo: 2);

    $context = makePayCycleContext($this->user);
    $result = $this->stage->execute($context);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    $nextPayDate = CarbonImmutable::parse($suggestion->payload['next_pay_date']);

    expect($suggestion->payload['pay_frequency'])->toBe($expectedFrequency)
        ->and($nextPayDate->greaterThan(CarbonImmutable::today()))->toBeTrue();
})->with([
    'weekly' => [7, 'weekly'],
    'fortnightly' => [14, 'fortnightly'],
    'monthly' => [30, 'monthly'],
]);

// ──────────────────────────────────────────────
// Payload
// ──────────────────────────────────────────────

test('payload contains all required fields with correct types', function () {
    withPrimaryAccount($this->user, $this->account);
    seedSalary($this->user, $this->account, intervalDays: 30);

    $context = makePayCycleContext($this->user);
    $result = $this->stage->execute($context);

    $payload = AnalysisSuggestion::find($result->suggestionIds[0])->payload;

    expect($payload)
        ->toHaveKeys([
            'pay_amount',
            'pay_frequency',
            'next_pay_date',
            'source_account_id',
            'source_description',
            'detected_dates',
            'confidence_score',
        ])
        ->and($payload['pay_amount'])->toBeInt()
        ->and($payload['pay_frequency'])->toBeString()
        ->and($payload['next_pay_date'])->toBeString()
        ->and($payload['source_account_id'])->toBe($this->account->id)
        ->and($payload['source_description'])->toBeString()
        ->and($payload['detected_dates'])->toBeArray()
        ->and($payload['confidence_score'])->toBeFloat();
});

test('detected dates are sorted chronologically', function () {
    withPrimaryAccount($this->user, $this->account);
    seedSalary($this->user, $this->account, intervalDays: 30, count: 4);

    $context = makePayCycleContext($this->user);
    $result = $this->stage->execute($context);

    $detectedDates = AnalysisSuggestion::find($result->suggestionIds[0])->payload['detected_dates'];

    $sorted = $detectedDates;
    sort($sorted);

    expect($detectedDates)->toBe($sorted)
        ->and($detectedDates)->toHaveCount(4);
});

// ──────────────────────────────────────────────
// Integration
// ──────────────────────────────────────────────

test('full pipeline auto-applies pay cycle on first sync', function () {
    seedSalary($this->user, $this->account, amount: 300_000, intervalDays: 30);

    $pipeline = app(TransactionAnalysisPipeline::class);
    $run = $pipeline->run($this->user, PipelineTrigger::Sync);

    expect($run->stages_completed)->toContain('identify-primary-account')
        ->and($run->stages_completed)->toContain('set-pay-cycle');

    $user = $this->user->fresh();
    expect($user->primary_account_id)->toBe($this->account->id)
        ->and($user->pay_amount)->toBe(300_000)
        ->and($user->pay_frequency)->toBe(PayFrequency::Monthly);

    $payCycleSuggestion = AnalysisSuggestion::where('pipeline_run_id', $run->id)
        ->where('type', SuggestionType::PayCycle)
        ->first();

    expect($payCycleSuggestion)->not->toBeNull()
        ->and($payCycleSuggestion->payload['source_account_id'])->toBe($this->account->id);
});

test('re-running the pipeline never overwrites a manually-set pay cycle', function () {
    withPrimaryAccount($this->user, $this->account);
    $manualNextPay = CarbonImmutable::today()->addDays(6)->format('Y-m-d');
    $this->user->update([
        'pay_amount' => 199_000,
        'pay_frequency' => 'weekly',
        'next_pay_date' => $manualNextPay,
    ]);

    // Detected pattern differs from the manual values.
    seedSalary($this->user, $this->account, amount: 300_000, intervalDays: 30);

    $pipeline = app(TransactionAnalysisPipeline::class);
    $pipeline->run($this->user, PipelineTrigger::Sync);

    $user = $this->user->fresh();
    expect($user->pay_amount)->toBe(199_000)
        ->and($user->pay_frequency)->toBe(PayFrequency::Weekly)
        ->and($user->next_pay_date->format('Y-m-d'))->toBe($manualNextPay);
});
