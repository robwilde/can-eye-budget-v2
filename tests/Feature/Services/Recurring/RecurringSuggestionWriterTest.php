<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\RecurringCandidate;
use App\Enums\RecurrenceFrequency;
use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineAuditEntry;
use App\Models\PipelineRun;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\Recurring\RecurringSuggestionWriter;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->run = PipelineRun::factory()->manual()->for($this->user)->create();
    $this->writer = app(RecurringSuggestionWriter::class);
});

function writerCandidate(
    Account $account,
    string $description = 'NETFLIX',
    int $amount = 1699,
    TransactionDirection $direction = TransactionDirection::Debit,
    RecurrenceFrequency $frequency = RecurrenceFrequency::EveryMonth,
): RecurringCandidate {
    return new RecurringCandidate(
        description: $description,
        cleanDescription: mb_convert_case($description, MB_CASE_TITLE, 'UTF-8'),
        amount: $amount,
        direction: $direction,
        frequency: $frequency,
        accountId: $account->id,
        categoryId: null,
        matchedTransactionIds: [101, 102, 103],
        startDate: CarbonImmutable::parse('2026-01-15'),
        confidenceScore: 0.88,
    );
}

function expectWriterSkippedAudit(PipelineRun $run, string $reason, string $description, Account $account): void
{
    $audit = PipelineAuditEntry::query()
        ->where('pipeline_run_id', $run->id)
        ->where('stage', 'identify-recurring-transactions')
        ->where('action', 'skipped')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->metadata['reason'])->toBe($reason)
        ->and($audit->metadata['description'])->toBe($description)
        ->and($audit->metadata['account_id'])->toBe($account->id);
}

test('persists pending recurring suggestions from candidates', function () {
    $candidate = writerCandidate($this->account);

    $ids = $this->writer->writeForRun($this->user, $this->run, collect([$candidate]));

    expect($ids)->toHaveCount(1);

    $suggestion = AnalysisSuggestion::find($ids[0]);
    expect($suggestion)->not->toBeNull()
        ->and($suggestion->user_id)->toBe($this->user->id)
        ->and($suggestion->pipeline_run_id)->toBe($this->run->id)
        ->and($suggestion->type)->toBe(SuggestionType::RecurringTransaction)
        ->and($suggestion->status)->toBe(SuggestionStatus::Pending)
        ->and($suggestion->payload)->toBe([
            'description' => 'NETFLIX',
            'clean_description' => 'Netflix',
            'amount' => 1699,
            'direction' => 'debit',
            'frequency' => 'every-month',
            'account_id' => $this->account->id,
            'category_id' => null,
            'matched_transaction_ids' => [101, 102, 103],
            'start_date' => '2026-01-15',
            'confidence_score' => 0.88,
        ]);
});

test('skips noise candidates with audit reason', function () {
    $candidate = writerCandidate($this->account, 'ROUND UP TRANSFER TO NETFLIX', 101);

    $ids = $this->writer->writeForRun($this->user, $this->run, collect([$candidate]));

    expect($ids)->toBeEmpty()
        ->and(AnalysisSuggestion::query()->count())->toBe(0);
    expectWriterSkippedAudit($this->run, 'noise', 'ROUND UP TRANSFER TO NETFLIX', $this->account);
});

test('skips existing accepted suggestions with audit reason', function () {
    $existingRun = PipelineRun::factory()->completed()->for($this->user)->create();
    AnalysisSuggestion::factory()->recurringTransaction()->accepted()->create([
        'pipeline_run_id' => $existingRun->id,
        'user_id' => $this->user->id,
        'payload' => [
            'description' => 'NETFLIX',
            'account_id' => $this->account->id,
        ],
    ]);

    $ids = $this->writer->writeForRun($this->user, $this->run, collect([writerCandidate($this->account)]));

    expect($ids)->toBeEmpty()
        ->and(AnalysisSuggestion::query()->where('pipeline_run_id', $this->run->id)->count())->toBe(0);
    expectWriterSkippedAudit($this->run, 'existing_accepted_suggestion', 'NETFLIX', $this->account);
});

test('skips matching active planned transactions with audit reason', function () {
    PlannedTransaction::factory()->for($this->user)->create([
        'account_id' => $this->account->id,
        'description' => 'Netflix',
        'direction' => TransactionDirection::Debit,
        'frequency' => RecurrenceFrequency::EveryMonth,
        'amount' => 1699,
        'is_active' => true,
    ]);

    $ids = $this->writer->writeForRun($this->user, $this->run, collect([writerCandidate($this->account)]));

    expect($ids)->toBeEmpty()
        ->and(AnalysisSuggestion::query()->where('pipeline_run_id', $this->run->id)->count())->toBe(0);
    expectWriterSkippedAudit($this->run, 'existing_planned_transaction', 'NETFLIX', $this->account);
});

test('skips recent rejections with audit reason', function () {
    $existingRun = PipelineRun::factory()->completed()->for($this->user)->create();
    AnalysisSuggestion::factory()->recurringTransaction()->rejected()->create([
        'pipeline_run_id' => $existingRun->id,
        'user_id' => $this->user->id,
        'resolved_at' => now()->subDays(30),
        'payload' => [
            'description' => 'NETFLIX',
            'account_id' => $this->account->id,
        ],
    ]);

    $ids = $this->writer->writeForRun($this->user, $this->run, collect([writerCandidate($this->account)]));

    expect($ids)->toBeEmpty()
        ->and(AnalysisSuggestion::query()->where('pipeline_run_id', $this->run->id)->count())->toBe(0);
    expectWriterSkippedAudit($this->run, 'recently_rejected', 'NETFLIX', $this->account);
});

test('continues persisting later candidates after skipping an earlier one', function () {
    $noise = writerCandidate($this->account, 'ROUND UP TRANSFER TO SAV', 101);
    $valid = writerCandidate($this->account, 'SPOTIFY', 1499);

    $ids = $this->writer->writeForRun($this->user, $this->run, collect([$noise, $valid]));

    expect($ids)->toHaveCount(1)
        ->and(AnalysisSuggestion::find($ids[0])->payload['description'])->toBe('SPOTIFY')
        ->and(AnalysisSuggestion::query()->where('pipeline_run_id', $this->run->id)->count())->toBe(1);
    expectWriterSkippedAudit($this->run, 'noise', 'ROUND UP TRANSFER TO SAV', $this->account);
});
