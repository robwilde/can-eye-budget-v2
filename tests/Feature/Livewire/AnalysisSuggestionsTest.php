<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PayFrequency;
use App\Enums\RecurrenceFrequency;
use App\Enums\SuggestionStatus;
use App\Enums\TransactionDirection;
use App\Livewire\AnalysisSuggestions;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\Category;
use App\Models\PipelineRun;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->pipelineRun = PipelineRun::factory()->for($this->user)->create();
});

function primaryAccountPayload(int $accountId, string $accountName = 'Everyday Account'): array
{
    return [
        'account_id' => $accountId,
        'account_name' => $accountName,
        'income_amount' => 300000,
        'income_frequency' => 'fortnightly',
        'income_description' => 'EMPLOYER PTY LTD',
        'confidence_score' => 0.85,
        'matched_transaction_ids' => [],
        'outbound_transfer_count' => 3,
    ];
}

function payCyclePayload(int $accountId, ?string $nextPayDate = null): array
{
    return [
        'pay_amount' => 300000,
        'pay_frequency' => 'fortnightly',
        'next_pay_date' => $nextPayDate ?? now()->addWeeks(2)->format('Y-m-d'),
        'source_account_id' => $accountId,
        'source_description' => 'EMPLOYER PTY LTD',
        'detected_dates' => ['2026-03-01', '2026-03-15'],
    ];
}

function recurringPayload(int $accountId, array $overrides = []): array
{
    return array_merge([
        'description' => 'NETFLIX',
        'clean_description' => 'Netflix',
        'amount' => 1699,
        'direction' => 'debit',
        'frequency' => 'every-month',
        'account_id' => $accountId,
        'category_id' => null,
        'matched_transaction_ids' => [],
        'start_date' => '2026-01-15',
        'confidence_score' => 0.92,
    ], $overrides);
}

function createSuggestion(object $testContext, string $type, array $payload, array $overrides = []): AnalysisSuggestion
{
    $factory = AnalysisSuggestion::factory();

    $factory = match ($type) {
        'primary' => $factory->primaryAccount(),
        'payCycle' => $factory->payCycle(),
        'recurring' => $factory->recurringTransaction(),
    };

    return $factory->create(array_merge([
        'pipeline_run_id' => $testContext->pipelineRun->id,
        'user_id' => $testContext->user->id,
        'payload' => $payload,
    ], $overrides));
}

// ─── Rendering ────────────────────────────────────────────

test('renders empty when no pending suggestions', function () {
    Livewire::actingAs($this->user)
        ->test(AnalysisSuggestions::class)
        ->assertDontSee('Analysis Suggestions');
});

test('displays primary account suggestion with account name', function () {
    createSuggestion($this, 'primary', primaryAccountPayload($this->account->id));

    Livewire::actingAs($this->user)
        ->test(AnalysisSuggestions::class)
        ->assertSee('Primary Account Detected')
        ->assertSee('Everyday Account')
        ->assertSee('$3,000.00')
        ->assertSee('Fortnightly');
});

test('displays pay cycle suggestion with pre-populated editable fields', function () {
    $nextPayDate = now()->addWeeks(2)->format('Y-m-d');

    createSuggestion($this, 'payCycle', payCyclePayload($this->account->id, $nextPayDate));

    Livewire::actingAs($this->user)
        ->test(AnalysisSuggestions::class)
        ->assertSee('Pay Cycle Detected')
        ->assertSet('payAmount', '3000.00')
        ->assertSet('payFrequency', 'fortnightly')
        ->assertSet('nextPayDate', $nextPayDate);
});

test('displays recurring transaction suggestions with details', function () {
    createSuggestion($this, 'recurring', recurringPayload($this->account->id, [
        'matched_transaction_ids' => [1, 2, 3],
    ]));

    Livewire::actingAs($this->user)
        ->test(AnalysisSuggestions::class)
        ->assertSee('Recurring Transactions Detected')
        ->assertSee('Netflix')
        ->assertSee('$16.99')
        ->assertSee('Every month')
        ->assertSee('3 matches');
});

test('does not display already resolved suggestions', function () {
    createSuggestion($this, 'primary', primaryAccountPayload($this->account->id, 'Resolved Account'), [
        'status' => SuggestionStatus::Accepted,
        'resolved_at' => now(),
    ]);

    createSuggestion($this, 'payCycle', payCyclePayload($this->account->id), [
        'status' => SuggestionStatus::Rejected,
        'resolved_at' => now(),
    ]);

    Livewire::actingAs($this->user)
        ->test(AnalysisSuggestions::class)
        ->assertDontSee('Analysis Suggestions')
        ->assertDontSee('Resolved Account');
});

// ─── Accept Primary Account ──────────────────────────────

test('accept primary account sets user primary_account_id and marks accepted', function () {
    $suggestion = createSuggestion($this, 'primary', primaryAccountPayload($this->account->id));

    Livewire::actingAs($this->user)
        ->test(AnalysisSuggestions::class)
        ->call('acceptPrimaryAccount', $suggestion->id);

    expect($this->user->fresh()->primary_account_id)->toBe($this->account->id)
        ->and($suggestion->fresh()->status)->toBe(SuggestionStatus::Accepted)
        ->and($suggestion->fresh()->resolved_at)->not->toBeNull();
});

test('cannot accept primary account suggestion belonging to another user', function () {
    $otherUser = User::factory()->create();
    $otherAccount = Account::factory()->for($otherUser)->create();
    $otherPipelineRun = PipelineRun::factory()->for($otherUser)->create();

    $suggestion = AnalysisSuggestion::factory()
        ->primaryAccount()
        ->create([
            'pipeline_run_id' => $otherPipelineRun->id,
            'user_id' => $otherUser->id,
            'payload' => primaryAccountPayload($otherAccount->id, 'Other Account'),
        ]);

    Livewire::actingAs($this->user)
        ->test(AnalysisSuggestions::class)
        ->call('acceptPrimaryAccount', $suggestion->id);

    expect($this->user->fresh()->primary_account_id)->toBeNull()
        ->and($suggestion->fresh()->status)->toBe(SuggestionStatus::Pending);
});

// ─── Accept Pay Cycle ────────────────────────────────────

test('accept pay cycle updates user pay fields and marks accepted', function () {
    $nextPayDate = now()->addWeeks(2)->format('Y-m-d');

    $suggestion = createSuggestion($this, 'payCycle', payCyclePayload($this->account->id, $nextPayDate));

    Livewire::actingAs($this->user)
        ->test(AnalysisSuggestions::class)
        ->call('acceptPayCycle', $suggestion->id);

    $user = $this->user->fresh();

    expect($user->pay_amount)->toBe(300000)
        ->and($user->pay_frequency)->toBe(PayFrequency::Fortnightly)
        ->and($user->next_pay_date->format('Y-m-d'))->toBe($nextPayDate)
        ->and($suggestion->fresh()->status)->toBe(SuggestionStatus::Accepted);
});

test('accept pay cycle uses edited form values when user modifies fields', function () {
    $originalDate = now()->addWeeks(2)->format('Y-m-d');
    $editedDate = now()->addWeeks(3)->format('Y-m-d');

    $suggestion = createSuggestion($this, 'payCycle', payCyclePayload($this->account->id, $originalDate));

    Livewire::actingAs($this->user)
        ->test(AnalysisSuggestions::class)
        ->set('payAmount', '3500.00')
        ->set('payFrequency', 'weekly')
        ->set('nextPayDate', $editedDate)
        ->call('acceptPayCycle', $suggestion->id);

    $user = $this->user->fresh();

    expect($user->pay_amount)->toBe(350000)
        ->and($user->pay_frequency)->toBe(PayFrequency::Weekly)
        ->and($user->next_pay_date->format('Y-m-d'))->toBe($editedDate);
});

test('accept pay cycle validates required fields and rejects invalid input', function () {
    $suggestion = createSuggestion($this, 'payCycle', payCyclePayload($this->account->id));

    Livewire::actingAs($this->user)
        ->test(AnalysisSuggestions::class)
        ->set('payAmount', '')
        ->set('payFrequency', '')
        ->set('nextPayDate', '')
        ->call('acceptPayCycle', $suggestion->id)
        ->assertHasErrors(['payAmount', 'payFrequency', 'nextPayDate']);

    expect($suggestion->fresh()->status)->toBe(SuggestionStatus::Pending);
});

// ─── Accept Recurring Transaction ────────────────────────

test('accept recurring transaction creates planned transaction with correct attributes', function () {
    $category = Category::factory()->create();

    $suggestion = createSuggestion($this, 'recurring', recurringPayload($this->account->id, [
        'category_id' => $category->id,
    ]));

    Livewire::actingAs($this->user)
        ->test(AnalysisSuggestions::class)
        ->call('acceptRecurringTransaction', $suggestion->id);

    $planned = PlannedTransaction::where('user_id', $this->user->id)->first();

    expect($planned)->not->toBeNull()
        ->and($planned->account_id)->toBe($this->account->id)
        ->and($planned->amount)->toBe(1699)
        ->and($planned->direction)->toBe(TransactionDirection::Debit)
        ->and($planned->description)->toBe('Netflix')
        ->and($planned->frequency)->toBe(RecurrenceFrequency::EveryMonth)
        ->and($planned->is_active)->toBeTrue()
        ->and($planned->category_id)->toBe($category->id)
        ->and($suggestion->fresh()->status)->toBe(SuggestionStatus::Accepted);
});

test('accept recurring transaction links matched transactions via planned_transaction_id', function () {
    $tx1 = Transaction::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->account->id]);
    $tx2 = Transaction::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->account->id]);

    $suggestion = createSuggestion($this, 'recurring', recurringPayload($this->account->id, [
        'clean_description' => 'Spotify',
        'amount' => 1299,
        'matched_transaction_ids' => [$tx1->id, $tx2->id],
    ]));

    Livewire::actingAs($this->user)
        ->test(AnalysisSuggestions::class)
        ->call('acceptRecurringTransaction', $suggestion->id);

    $planned = PlannedTransaction::where('user_id', $this->user->id)->first();

    expect($tx1->fresh()->planned_transaction_id)->toBe($planned->id)
        ->and($tx2->fresh()->planned_transaction_id)->toBe($planned->id);
});

test('accept recurring transaction applies selected category to planned and linked transactions', function () {
    $category = Category::factory()->create();
    $tx1 = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
    ]);

    $suggestion = createSuggestion($this, 'recurring', recurringPayload($this->account->id, [
        'clean_description' => 'Gym Membership',
        'amount' => 5000,
        'frequency' => 'every-2-weeks',
        'category_id' => null,
        'matched_transaction_ids' => [$tx1->id],
    ]));

    Livewire::actingAs($this->user)
        ->test(AnalysisSuggestions::class)
        ->set("recurringCategories.{$suggestion->id}", (string) $category->id)
        ->call('acceptRecurringTransaction', $suggestion->id);

    $planned = PlannedTransaction::where('user_id', $this->user->id)->first();

    expect($planned->category_id)->toBe($category->id)
        ->and($tx1->fresh()->category_id)->toBe($category->id);
});

test('accept recurring transaction falls back to null for hidden category', function () {
    $hiddenCategory = Category::factory()->hidden()->create();

    $suggestion = createSuggestion($this, 'recurring', recurringPayload($this->account->id));

    Livewire::actingAs($this->user)
        ->test(AnalysisSuggestions::class)
        ->set("recurringCategories.{$suggestion->id}", (string) $hiddenCategory->id)
        ->call('acceptRecurringTransaction', $suggestion->id);

    $planned = PlannedTransaction::where('user_id', $this->user->id)->first();

    expect($planned)->not->toBeNull()
        ->and($planned->category_id)->toBeNull();
});

// ─── Reject ──────────────────────────────────────────────

test('reject marks suggestion as rejected with resolved_at', function () {
    $suggestion = createSuggestion($this, 'primary', primaryAccountPayload($this->account->id));

    Livewire::actingAs($this->user)
        ->test(AnalysisSuggestions::class)
        ->call('rejectSuggestion', $suggestion->id);

    expect($suggestion->fresh()->status)->toBe(SuggestionStatus::Rejected)
        ->and($suggestion->fresh()->resolved_at)->not->toBeNull();
});

test('cannot reject already resolved suggestion', function () {
    $suggestion = createSuggestion($this, 'primary', primaryAccountPayload($this->account->id), [
        'status' => SuggestionStatus::Accepted,
        'resolved_at' => now(),
    ]);

    Livewire::actingAs($this->user)
        ->test(AnalysisSuggestions::class)
        ->call('rejectSuggestion', $suggestion->id);

    expect($suggestion->fresh()->status)->toBe(SuggestionStatus::Accepted);
});

// ─── Edge Cases ──────────────────────────────────────────

test('component hidden when all suggestions are resolved', function () {
    createSuggestion($this, 'primary', primaryAccountPayload($this->account->id), [
        'status' => SuggestionStatus::Accepted,
        'resolved_at' => now(),
    ]);

    createSuggestion($this, 'payCycle', payCyclePayload($this->account->id), [
        'status' => SuggestionStatus::Rejected,
        'resolved_at' => now(),
    ]);

    Livewire::actingAs($this->user)
        ->test(AnalysisSuggestions::class)
        ->assertDontSee('Analysis Suggestions');
});
