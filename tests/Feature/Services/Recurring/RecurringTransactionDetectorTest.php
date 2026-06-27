<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\RecurringCandidate;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\Category;
use App\Models\PipelineAuditEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Recurring\RecurringTransactionDetector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->detector = app(RecurringTransactionDetector::class);
});

function createDetectorTransaction(User $user, Account $account, array $overrides = []): Transaction
{
    return Transaction::factory()
        ->fromBasiq()
        ->for($user)
        ->for($account)
        ->create($overrides);
}

function createDetectorDatedGroup(
    User $user,
    Account $account,
    string $merchantName,
    int $amount,
    array $dates,
    TransactionDirection $direction = TransactionDirection::Debit,
    ?int $categoryId = null,
): Collection {
    return collect($dates)
        ->map(fn (string $date): Transaction => createDetectorTransaction($user, $account, [
            'merchant_name' => $merchantName,
            'amount' => $amount,
            'direction' => $direction,
            'post_date' => CarbonImmutable::parse($date),
            'category_id' => $categoryId,
        ]));
}

test('detects monthly recurring transactions as candidates with payload-compatible fields', function () {
    $category = Category::factory()->create();
    $transactions = createDetectorDatedGroup($this->user, $this->account, 'Netflix', 1699, [
        '2026-01-15',
        '2026-02-15',
        '2026-03-15',
    ], categoryId: $category->id);

    $candidates = $this->detector->detect($this->user);

    expect($candidates)->toHaveCount(1);

    $candidate = $candidates->first();
    expect($candidate)->toBeInstanceOf(RecurringCandidate::class)
        ->and($candidate->description)->toBe('NETFLIX')
        ->and($candidate->cleanDescription)->toBe('Netflix')
        ->and($candidate->amount)->toBe(1699)
        ->and($candidate->direction)->toBe(TransactionDirection::Debit)
        ->and($candidate->frequency)->toBe(RecurrenceFrequency::EveryMonth)
        ->and($candidate->accountId)->toBe($this->account->id)
        ->and($candidate->categoryId)->toBe($category->id)
        ->and($candidate->matchedTransactionIds)->toBe($transactions->pluck('id')->values()->all())
        ->and($candidate->startDate)->toBeInstanceOf(CarbonImmutable::class)
        ->and($candidate->startDate->toDateString())->toBe('2026-01-15')
        ->and($candidate->confidenceScore)->toBeFloat();

    expect($candidate->toSuggestionPayload())->toHaveKeys([
        'description', 'clean_description', 'amount', 'direction', 'frequency',
        'account_id', 'category_id', 'matched_transaction_ids', 'start_date', 'confidence_score',
    ]);
});

test('detects weekly recurring transactions', function () {
    createDetectorDatedGroup($this->user, $this->account, 'Coffee Club', 550, [
        '2026-01-05',
        '2026-01-12',
        '2026-01-19',
        '2026-01-26',
    ]);

    $candidate = $this->detector->detect($this->user)->first();

    expect($candidate)->toBeInstanceOf(RecurringCandidate::class)
        ->and($candidate->frequency)->toBe(RecurrenceFrequency::EveryWeek);
});

test('account id scopes detection to one account', function () {
    $accountB = Account::factory()->for($this->user)->create();

    createDetectorDatedGroup($this->user, $this->account, 'Spotify', 1199, [
        '2026-01-10',
        '2026-02-10',
        '2026-03-10',
    ]);
    createDetectorDatedGroup($this->user, $accountB, 'Netflix', 1699, [
        '2026-01-15',
        '2026-02-15',
        '2026-03-15',
    ]);

    $candidates = $this->detector->detect($this->user, $this->account->id);

    expect($candidates)->toHaveCount(1)
        ->and($candidates->first()->accountId)->toBe($this->account->id)
        ->and($candidates->first()->description)->toBe('SPOTIFY');
});

test('requires at least two transactions', function () {
    createDetectorTransaction($this->user, $this->account, [
        'merchant_name' => 'Solo Purchase',
        'amount' => 2000,
        'post_date' => '2026-01-15',
    ]);

    expect($this->detector->detect($this->user))->toBeEmpty();
});

test('separates debit and credit candidates for the same merchant', function () {
    createDetectorDatedGroup($this->user, $this->account, 'Transfer Corp', 10000, [
        '2026-01-15',
        '2026-02-15',
        '2026-03-15',
    ], TransactionDirection::Debit);
    createDetectorDatedGroup($this->user, $this->account, 'Transfer Corp', 10000, [
        '2026-01-16',
        '2026-02-16',
        '2026-03-16',
    ], TransactionDirection::Credit);

    $directions = $this->detector->detect($this->user)
        ->pluck('direction')
        ->sortBy(fn (TransactionDirection $direction): string => $direction->value)
        ->values()
        ->all();

    expect($directions)->toBe([TransactionDirection::Credit, TransactionDirection::Debit]);
});

test('detect is pure and creates no suggestions or audit entries', function () {
    createDetectorDatedGroup($this->user, $this->account, 'Netflix', 1699, [
        '2026-01-15',
        '2026-02-15',
        '2026-03-15',
    ]);

    $this->detector->detect($this->user);

    expect(AnalysisSuggestion::query()->count())->toBe(0)
        ->and(PipelineAuditEntry::query()->count())->toBe(0);
});
