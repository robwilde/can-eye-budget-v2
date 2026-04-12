<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\PipelineContext;
use App\Enums\RecurrenceFrequency;
use App\Enums\SuggestionType;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\Category;
use App\Models\PipelineAuditEntry;
use App\Models\PipelineRun;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PipelineStages\IdentifyRecurringTransactionsStage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->pipelineRun = PipelineRun::factory()->for($this->user)->create();
    $this->context = new PipelineContext(
        user: $this->user,
        pipelineRun: $this->pipelineRun,
        isFirstSync: false,
    );
    $this->stage = new IdentifyRecurringTransactionsStage;
});

function createBasiqTransaction(User $user, Account $account, array $overrides = []): Transaction
{
    return Transaction::factory()->fromBasiq()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ], $overrides));
}

function createMonthlyGroup(User $user, Account $account, string $merchantName, int $amount, int $count = 3, ?string $startDate = null): Collection
{
    $start = CarbonImmutable::parse($startDate ?? '2026-01-15');
    $transactions = collect();

    for ($i = 0; $i < $count; $i++) {
        $transactions->push(createBasiqTransaction($user, $account, [
            'merchant_name' => $merchantName,
            'amount' => $amount,
            'post_date' => $start->addMonthsNoOverflow($i),
            'direction' => TransactionDirection::Debit,
        ]));
    }

    return $transactions;
}

// ─── Detection ──────────────────────────────────────────────────────────

test('detects monthly recurring transactions', function () {
    createMonthlyGroup($this->user, $this->account, 'Netflix', 1699, 3);

    $result = $this->stage->execute($this->context);

    expect($result->success)->toBeTrue()
        ->and($result->suggestionIds)->toHaveCount(1);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    expect($suggestion->payload['frequency'])->toBe(RecurrenceFrequency::EveryMonth->value)
        ->and($suggestion->payload['description'])->toBe('NETFLIX');
});

test('detects weekly recurring transactions', function () {
    $start = CarbonImmutable::parse('2026-01-05');

    for ($i = 0; $i < 4; $i++) {
        createBasiqTransaction($this->user, $this->account, [
            'merchant_name' => 'Coffee Club',
            'amount' => 550,
            'post_date' => $start->addWeeks($i),
        ]);
    }

    $result = $this->stage->execute($this->context);

    expect($result->success)->toBeTrue()
        ->and($result->suggestionIds)->toHaveCount(1);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    expect($suggestion->payload['frequency'])->toBe(RecurrenceFrequency::EveryWeek->value);
});

test('detects fortnightly recurring transactions', function () {
    $start = CarbonImmutable::parse('2026-01-03');

    for ($i = 0; $i < 3; $i++) {
        createBasiqTransaction($this->user, $this->account, [
            'merchant_name' => 'Lawn Care',
            'amount' => 8000,
            'post_date' => $start->addWeeks($i * 2),
        ]);
    }

    $result = $this->stage->execute($this->context);

    expect($result->success)->toBeTrue()
        ->and($result->suggestionIds)->toHaveCount(1);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    expect($suggestion->payload['frequency'])->toBe(RecurrenceFrequency::Every2Weeks->value);
});

test('detects quarterly recurring transactions', function () {
    $start = CarbonImmutable::parse('2025-04-01');

    for ($i = 0; $i < 3; $i++) {
        createBasiqTransaction($this->user, $this->account, [
            'merchant_name' => 'Insurance Corp',
            'amount' => 45000,
            'post_date' => $start->addMonthsNoOverflow($i * 3),
        ]);
    }

    $result = $this->stage->execute($this->context);

    expect($result->success)->toBeTrue()
        ->and($result->suggestionIds)->toHaveCount(1);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    expect($suggestion->payload['frequency'])->toBe(RecurrenceFrequency::Every3Months->value);
});

test('requires minimum 2 transactions to detect pattern', function () {
    createBasiqTransaction($this->user, $this->account, [
        'merchant_name' => 'Solo Purchase',
        'amount' => 2000,
        'post_date' => '2026-01-15',
    ]);

    $result = $this->stage->execute($this->context);

    expect($result->success)->toBeTrue()
        ->and($result->suggestionIds)->toBeEmpty();
});

test('separates by account creating distinct suggestions', function () {
    $account2 = Account::factory()->for($this->user)->create();

    createMonthlyGroup($this->user, $this->account, 'Spotify', 1199, 3);
    createMonthlyGroup($this->user, $account2, 'Spotify', 1199, 3);

    $result = $this->stage->execute($this->context);

    expect($result->success)->toBeTrue()
        ->and($result->suggestionIds)->toHaveCount(2);
});

test('separates debits and credits for same merchant', function () {
    $start = CarbonImmutable::parse('2026-01-15');

    for ($i = 0; $i < 3; $i++) {
        createBasiqTransaction($this->user, $this->account, [
            'merchant_name' => 'Transfer Corp',
            'amount' => 10000,
            'post_date' => $start->addMonthsNoOverflow($i),
            'direction' => TransactionDirection::Debit,
        ]);

        createBasiqTransaction($this->user, $this->account, [
            'merchant_name' => 'Transfer Corp',
            'amount' => 10000,
            'post_date' => $start->addMonthsNoOverflow($i)->addDays(1),
            'direction' => TransactionDirection::Credit,
        ]);
    }

    $result = $this->stage->execute($this->context);

    expect($result->success)->toBeTrue()
        ->and($result->suggestionIds)->toHaveCount(2);

    $suggestions = AnalysisSuggestion::whereIn('id', $result->suggestionIds)->get();
    $directions = $suggestions->pluck('payload.direction')->sort()->values();
    expect($directions->all())->toBe(['credit', 'debit']);
});

// ─── Normalization ──────────────────────────────────────────────────────

test('prefers merchant_name over description for grouping', function () {
    $start = CarbonImmutable::parse('2026-01-15');

    for ($i = 0; $i < 3; $i++) {
        createBasiqTransaction($this->user, $this->account, [
            'merchant_name' => 'Woolworths',
            'description' => 'WOOLWORTHS '.fake()->randomNumber(4).' SYDNEY AU',
            'amount' => 8500,
            'post_date' => $start->addMonthsNoOverflow($i),
        ]);
    }

    $result = $this->stage->execute($this->context);

    expect($result->suggestionIds)->toHaveCount(1);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    expect($suggestion->payload['description'])->toBe('WOOLWORTHS');
});

test('prefers clean_description when merchant_name is null', function () {
    $start = CarbonImmutable::parse('2026-01-15');

    for ($i = 0; $i < 3; $i++) {
        createBasiqTransaction($this->user, $this->account, [
            'merchant_name' => null,
            'clean_description' => 'BP Fuel Station',
            'description' => 'BP FUEL STATION '.fake()->randomNumber(4),
            'amount' => 6000,
            'post_date' => $start->addMonthsNoOverflow($i),
        ]);
    }

    $result = $this->stage->execute($this->context);

    expect($result->suggestionIds)->toHaveCount(1);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    expect($suggestion->payload['description'])->toBe('BP FUEL STATION');
});

test('strips card numbers from raw descriptions for grouping', function () {
    $start = CarbonImmutable::parse('2026-01-15');

    for ($i = 0; $i < 3; $i++) {
        createBasiqTransaction($this->user, $this->account, [
            'merchant_name' => null,
            'clean_description' => null,
            'description' => 'WOOLWORTHS 1234 SYDNEY AU',
            'amount' => 8500,
            'post_date' => $start->addMonthsNoOverflow($i),
        ]);
    }

    $result = $this->stage->execute($this->context);

    expect($result->suggestionIds)->toHaveCount(1);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    expect($suggestion->payload['description'])->toBe('WOOLWORTHS');
});

test('strips date patterns from raw descriptions for grouping', function () {
    $start = CarbonImmutable::parse('2026-01-15');
    $dates = ['12/03', '13/04', '14/05'];

    for ($i = 0; $i < 3; $i++) {
        createBasiqTransaction($this->user, $this->account, [
            'merchant_name' => null,
            'clean_description' => null,
            'description' => 'BP DANDENONG '.$dates[$i],
            'amount' => 7500,
            'post_date' => $start->addMonthsNoOverflow($i),
        ]);
    }

    $result = $this->stage->execute($this->context);

    expect($result->suggestionIds)->toHaveCount(1);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    expect($suggestion->payload['description'])->toBe('BP DANDENONG');
});

test('deduplicates repeated names in descriptions', function () {
    $start = CarbonImmutable::parse('2026-01-15');

    for ($i = 0; $i < 3; $i++) {
        createBasiqTransaction($this->user, $this->account, [
            'merchant_name' => null,
            'clean_description' => null,
            'description' => 'NETFLIX.COM NETFLIX.COM',
            'amount' => 1699,
            'post_date' => $start->addMonthsNoOverflow($i),
        ]);
    }

    $result = $this->stage->execute($this->context);

    expect($result->suggestionIds)->toHaveCount(1);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    expect($suggestion->payload['description'])->toBe('NETFLIX.COM');
});

// ─── Amount Consistency ─────────────────────────────────────────────────

test('rejects group where amounts vary more than 5 percent from median', function () {
    $start = CarbonImmutable::parse('2026-01-15');
    $amounts = [10000, 10000, 15000];

    for ($i = 0; $i < 3; $i++) {
        createBasiqTransaction($this->user, $this->account, [
            'merchant_name' => 'Variable Store',
            'amount' => $amounts[$i],
            'post_date' => $start->addMonthsNoOverflow($i),
        ]);
    }

    $result = $this->stage->execute($this->context);

    expect($result->suggestionIds)->toBeEmpty();
});

test('accepts group with amounts within 5 percent tolerance', function () {
    $start = CarbonImmutable::parse('2026-01-15');
    $amounts = [10000, 10200, 10400];

    for ($i = 0; $i < 3; $i++) {
        createBasiqTransaction($this->user, $this->account, [
            'merchant_name' => 'Consistent Store',
            'amount' => $amounts[$i],
            'post_date' => $start->addMonthsNoOverflow($i),
        ]);
    }

    $result = $this->stage->execute($this->context);

    expect($result->suggestionIds)->toHaveCount(1);
});

// ─── Idempotency ────────────────────────────────────────────────────────

test('skips when accepted suggestion exists with matching description and account', function () {
    $existingRun = PipelineRun::factory()->for($this->user)->completed()->create();
    AnalysisSuggestion::factory()->recurringTransaction()->accepted()->create([
        'pipeline_run_id' => $existingRun->id,
        'user_id' => $this->user->id,
        'payload' => [
            'description' => 'NETFLIX',
            'account_id' => $this->account->id,
        ],
    ]);

    createMonthlyGroup($this->user, $this->account, 'Netflix', 1699, 3);

    $result = $this->stage->execute($this->context);

    expect($result->suggestionIds)->toBeEmpty();
});

test('skips when matching active PlannedTransaction exists', function () {
    PlannedTransaction::factory()->for($this->user)->create([
        'account_id' => $this->account->id,
        'description' => 'Netflix',
        'frequency' => RecurrenceFrequency::EveryMonth,
        'amount' => 1699,
        'is_active' => true,
    ]);

    createMonthlyGroup($this->user, $this->account, 'Netflix', 1699, 3);

    $result = $this->stage->execute($this->context);

    expect($result->suggestionIds)->toBeEmpty();
});

test('skips when rejected suggestion within 90 days exists', function () {
    $existingRun = PipelineRun::factory()->for($this->user)->completed()->create();
    AnalysisSuggestion::factory()->recurringTransaction()->rejected()->create([
        'pipeline_run_id' => $existingRun->id,
        'user_id' => $this->user->id,
        'resolved_at' => now()->subDays(30),
        'payload' => [
            'description' => 'NETFLIX',
            'account_id' => $this->account->id,
        ],
    ]);

    createMonthlyGroup($this->user, $this->account, 'Netflix', 1699, 3);

    $result = $this->stage->execute($this->context);

    expect($result->suggestionIds)->toBeEmpty();
});

test('does not skip when rejection is older than 90 days', function () {
    $existingRun = PipelineRun::factory()->for($this->user)->completed()->create();
    AnalysisSuggestion::factory()->recurringTransaction()->rejected()->create([
        'pipeline_run_id' => $existingRun->id,
        'user_id' => $this->user->id,
        'resolved_at' => now()->subDays(91),
        'payload' => [
            'description' => 'NETFLIX',
            'account_id' => $this->account->id,
        ],
    ]);

    createMonthlyGroup($this->user, $this->account, 'Netflix', 1699, 3);

    $result = $this->stage->execute($this->context);

    expect($result->suggestionIds)->toHaveCount(1);
});

test('PlannedTransaction match uses 5 percent amount tolerance', function () {
    PlannedTransaction::factory()->for($this->user)->create([
        'account_id' => $this->account->id,
        'description' => 'Netflix',
        'frequency' => RecurrenceFrequency::EveryMonth,
        'amount' => 1750,
        'is_active' => true,
    ]);

    createMonthlyGroup($this->user, $this->account, 'Netflix', 1699, 3);

    $result = $this->stage->execute($this->context);

    expect($result->suggestionIds)->toBeEmpty();
});

// ─── Edge Cases ─────────────────────────────────────────────────────────

test('ignores non-Basiq transactions', function () {
    $start = CarbonImmutable::parse('2026-01-15');

    for ($i = 0; $i < 3; $i++) {
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'source' => TransactionSource::Manual,
            'description' => 'MANUAL ENTRY',
            'amount' => 5000,
            'post_date' => $start->addMonthsNoOverflow($i),
        ]);
    }

    $result = $this->stage->execute($this->context);

    expect($result->success)->toBeTrue()
        ->and($result->suggestionIds)->toBeEmpty();
});

test('ignores transactions already linked to PlannedTransaction', function () {
    $planned = PlannedTransaction::factory()->for($this->user)->create([
        'account_id' => $this->account->id,
    ]);

    $start = CarbonImmutable::parse('2026-01-15');

    for ($i = 0; $i < 3; $i++) {
        createBasiqTransaction($this->user, $this->account, [
            'merchant_name' => 'Linked Merchant',
            'amount' => 3000,
            'post_date' => $start->addMonthsNoOverflow($i),
            'planned_transaction_id' => $planned->id,
        ]);
    }

    $result = $this->stage->execute($this->context);

    expect($result->success)->toBeTrue()
        ->and($result->suggestionIds)->toBeEmpty();
});

test('returns success with empty suggestionIds when no Basiq transactions exist', function () {
    $result = $this->stage->execute($this->context);

    expect($result->success)->toBeTrue()
        ->and($result->suggestionIds)->toBeEmpty()
        ->and($result->stage)->toBe('identify-recurring-transactions');
});

// ─── Confidence ─────────────────────────────────────────────────────────

test('higher confidence for more matches', function () {
    $start = CarbonImmutable::parse('2025-07-15');

    for ($i = 0; $i < 3; $i++) {
        createBasiqTransaction($this->user, $this->account, [
            'merchant_name' => 'SmallGroup',
            'amount' => 2000,
            'post_date' => $start->addMonthsNoOverflow($i),
        ]);
    }

    $account2 = Account::factory()->for($this->user)->create();

    for ($i = 0; $i < 8; $i++) {
        createBasiqTransaction($this->user, $account2, [
            'merchant_name' => 'LargeGroup',
            'amount' => 2000,
            'post_date' => $start->addMonthsNoOverflow($i),
        ]);
    }

    $result = $this->stage->execute($this->context);

    expect($result->suggestionIds)->toHaveCount(2);

    $suggestions = AnalysisSuggestion::whereIn('id', $result->suggestionIds)
        ->get()
        ->keyBy(fn ($s) => $s->payload['description']);

    expect($suggestions['LARGEGROUP']->payload['confidence_score'])
        ->toBeGreaterThan($suggestions['SMALLGROUP']->payload['confidence_score']);
});

test('higher confidence for identical amounts versus varying', function () {
    $start = CarbonImmutable::parse('2026-01-15');

    for ($i = 0; $i < 4; $i++) {
        createBasiqTransaction($this->user, $this->account, [
            'merchant_name' => 'IdenticalCo',
            'amount' => 5000,
            'post_date' => $start->addMonthsNoOverflow($i),
        ]);
    }

    $account2 = Account::factory()->for($this->user)->create();
    $varyingAmounts = [5000, 5200, 4900, 5100];

    for ($i = 0; $i < 4; $i++) {
        createBasiqTransaction($this->user, $account2, [
            'merchant_name' => 'VaryingCo',
            'amount' => $varyingAmounts[$i],
            'post_date' => $start->addMonthsNoOverflow($i),
        ]);
    }

    $result = $this->stage->execute($this->context);

    expect($result->suggestionIds)->toHaveCount(2);

    $suggestions = AnalysisSuggestion::whereIn('id', $result->suggestionIds)
        ->get()
        ->keyBy(fn ($s) => $s->payload['description']);

    expect($suggestions['IDENTICALCO']->payload['confidence_score'])
        ->toBeGreaterThanOrEqual($suggestions['VARYINGCO']->payload['confidence_score']);
});

test('higher confidence for regular intervals', function () {
    $account2 = Account::factory()->for($this->user)->create();

    $regularDates = ['2026-01-15', '2026-02-15', '2026-03-15', '2026-04-15'];
    foreach ($regularDates as $date) {
        createBasiqTransaction($this->user, $this->account, [
            'merchant_name' => 'RegularCo',
            'amount' => 3000,
            'post_date' => $date,
        ]);
    }

    $irregularDates = ['2026-01-15', '2026-02-18', '2026-03-12', '2026-04-16'];
    foreach ($irregularDates as $date) {
        createBasiqTransaction($this->user, $account2, [
            'merchant_name' => 'IrregularCo',
            'amount' => 3000,
            'post_date' => $date,
        ]);
    }

    $result = $this->stage->execute($this->context);

    expect($result->suggestionIds)->toHaveCount(2);

    $suggestions = AnalysisSuggestion::whereIn('id', $result->suggestionIds)
        ->get()
        ->keyBy(fn ($s) => $s->payload['description']);

    expect($suggestions['REGULARCO']->payload['confidence_score'])
        ->toBeGreaterThan($suggestions['IRREGULARCO']->payload['confidence_score']);
});

// ─── Payload ────────────────────────────────────────────────────────────

test('payload contains all required fields with correct types', function () {
    $category = Category::factory()->create();
    $start = CarbonImmutable::parse('2026-01-15');

    for ($i = 0; $i < 3; $i++) {
        createBasiqTransaction($this->user, $this->account, [
            'merchant_name' => 'Netflix',
            'amount' => 1699,
            'post_date' => $start->addMonthsNoOverflow($i),
            'category_id' => $category->id,
        ]);
    }

    $result = $this->stage->execute($this->context);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    $payload = $suggestion->payload;

    expect($payload)
        ->toHaveKeys([
            'description', 'clean_description', 'amount', 'direction',
            'frequency', 'account_id', 'category_id', 'matched_transaction_ids',
            'start_date', 'confidence_score',
        ])
        ->and($payload['description'])->toBeString()
        ->and($payload['amount'])->toBeInt()
        ->and($payload['direction'])->toBeString()
        ->and($payload['frequency'])->toBeString()
        ->and($payload['account_id'])->toBeInt()
        ->and($payload['category_id'])->toBe($category->id)
        ->and($payload['matched_transaction_ids'])->toBeArray()
        ->and($payload['start_date'])->toBeString()
        ->and($payload['confidence_score'])->toBeFloat();
});

test('matched_transaction_ids contains all group transaction IDs', function () {
    $txns = createMonthlyGroup($this->user, $this->account, 'Netflix', 1699, 3);

    $result = $this->stage->execute($this->context);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    $expectedIds = $txns->pluck('id')->sort()->values()->all();
    $actualIds = collect($suggestion->payload['matched_transaction_ids'])->sort()->values()->all();

    expect($actualIds)->toBe($expectedIds);
});

test('start_date is earliest post_date in group', function () {
    createBasiqTransaction($this->user, $this->account, [
        'merchant_name' => 'DateTest',
        'amount' => 2000,
        'post_date' => '2026-03-15',
    ]);
    createBasiqTransaction($this->user, $this->account, [
        'merchant_name' => 'DateTest',
        'amount' => 2000,
        'post_date' => '2026-01-15',
    ]);
    createBasiqTransaction($this->user, $this->account, [
        'merchant_name' => 'DateTest',
        'amount' => 2000,
        'post_date' => '2026-02-15',
    ]);

    $result = $this->stage->execute($this->context);

    $suggestion = AnalysisSuggestion::find($result->suggestionIds[0]);
    expect($suggestion->payload['start_date'])->toBe('2026-01-15');
});

// ─── Audit ──────────────────────────────────────────────────────────────

test('creates audit entries for skipped candidates with reason in metadata', function () {
    $existingRun = PipelineRun::factory()->for($this->user)->completed()->create();
    AnalysisSuggestion::factory()->recurringTransaction()->accepted()->create([
        'pipeline_run_id' => $existingRun->id,
        'user_id' => $this->user->id,
        'payload' => [
            'description' => 'NETFLIX',
            'account_id' => $this->account->id,
        ],
    ]);

    createMonthlyGroup($this->user, $this->account, 'Netflix', 1699, 3);

    $this->stage->execute($this->context);

    $audit = PipelineAuditEntry::query()
        ->where('pipeline_run_id', $this->pipelineRun->id)
        ->where('stage', 'identify-recurring-transactions')
        ->where('action', 'skipped')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->metadata['reason'])->toBe('existing_accepted_suggestion')
        ->and($audit->metadata['description'])->toBe('NETFLIX')
        ->and($audit->metadata['account_id'])->toBe($this->account->id);
});

// ─── Integration ────────────────────────────────────────────────────────

test('stage is registered in pipeline and full run produces suggestions', function () {
    createMonthlyGroup($this->user, $this->account, 'Netflix', 1699, 3);

    $pipeline = app(App\Services\TransactionAnalysisPipeline::class);
    $run = $pipeline->run($this->user, App\Enums\PipelineTrigger::Sync);

    expect($run->stages_completed)->toContain('identify-recurring-transactions');

    $suggestions = AnalysisSuggestion::query()
        ->where('pipeline_run_id', $run->id)
        ->where('type', SuggestionType::RecurringTransaction)
        ->get();

    expect($suggestions)->toHaveCount(1);
});

// ─── Contract ───────────────────────────────────────────────────────────

test('key returns expected string', function () {
    expect($this->stage->key())->toBe('identify-recurring-transactions');
});

test('label returns human-readable string', function () {
    expect($this->stage->label())->toBe('Identify Recurring Transactions');
});

test('shouldRun always returns true', function () {
    expect($this->stage->shouldRun($this->context))->toBeTrue();
});
