<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\PayeeStatus;
use App\Enums\RecurrenceFrequency;
use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Enums\TransactionDirection;
use App\Livewire\RecurringTransactionReview;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\Category;
use App\Models\Payee;
use App\Models\PipelineRun;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Livewire;

function createRecurringReviewTransaction(User $user, Account $account, array $overrides = []): Transaction
{
    return Transaction::factory()->fromRedbark()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ], $overrides));
}

/** @return Collection<int, Transaction> */
function createRecurringReviewMonthlyGroup(
    User $user,
    Account $account,
    string $merchantName,
    int $amount,
    int $count = 3,
    ?string $startDate = null,
): Collection {
    $start = CarbonImmutable::parse($startDate ?? '2026-01-15');
    $transactions = collect();

    for ($i = 0; $i < $count; $i++) {
        $transactions->push(createRecurringReviewTransaction($user, $account, [
            'merchant_name' => $merchantName,
            'amount' => $amount,
            'post_date' => $start->addMonthsNoOverflow($i),
            'direction' => TransactionDirection::Debit,
        ]));
    }

    return $transactions;
}

beforeEach(function () {
    // Monthly fixtures end on 2026-03-15; pin "today" within the detector's
    // recency window so they are still suggested.
    $this->travelTo(CarbonImmutable::parse('2026-03-20'));
});

test('mount sets account id to the users primary account id', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    $primaryAccount = Account::factory()->for($user)->create();
    $user->update(['primary_account_id' => $primaryAccount->id]);

    Livewire::actingAs($user)
        ->test(RecurringTransactionReview::class)
        ->assertSet('accountId', $primaryAccount->id);
});

test('find recurring creates pending suggestions for the selected account and renders them', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $user->update(['primary_account_id' => $account->id]);

    createRecurringReviewMonthlyGroup($user, $account, 'Netflix', 1699, 3);

    Livewire::actingAs($user)
        ->test(RecurringTransactionReview::class)
        ->call('findRecurring')
        ->assertSee('Netflix')
        ->assertSee('$16.99')
        ->assertSee('Every month')
        ->assertSee('3 matches');

    $suggestion = AnalysisSuggestion::query()
        ->where('user_id', $user->id)
        ->ofType(SuggestionType::RecurringTransaction)
        ->pending()
        ->first();

    expect($suggestion)->not->toBeNull()
        ->and($suggestion->payload['account_id'])->toBe($account->id)
        ->and($suggestion->payload['clean_description'])->toBe('Netflix')
        ->and($suggestion->payload['amount'])->toBe(1699)
        ->and($suggestion->pipelineRun)->toBeInstanceOf(PipelineRun::class)
        ->and($suggestion->pipelineRun->trigger->value)->toBe('manual')
        ->and($suggestion->pipelineRun->status->value)->toBe('completed')
        ->and($suggestion->pipelineRun->completed_at)->not->toBeNull();
});

test('account scoping hides recurring series from another account while reviewing the primary account', function () {
    $user = User::factory()->create();
    $primaryAccount = Account::factory()->for($user)->create();
    $secondAccount = Account::factory()->for($user)->create();
    $user->update(['primary_account_id' => $primaryAccount->id]);

    createRecurringReviewMonthlyGroup($user, $primaryAccount, 'Netflix', 1699, 3);
    createRecurringReviewMonthlyGroup($user, $secondAccount, 'Spotify', 1299, 3);

    Livewire::actingAs($user)
        ->test(RecurringTransactionReview::class)
        ->call('findRecurring')
        ->assertSee('Netflix')
        ->assertDontSee('Spotify');

    $suggestions = AnalysisSuggestion::query()
        ->where('user_id', $user->id)
        ->ofType(SuggestionType::RecurringTransaction)
        ->pending()
        ->get();

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions->first()->payload['account_id'])->toBe($primaryAccount->id);
});

test('accept creates an active planned transaction and links matched transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $user->update(['primary_account_id' => $account->id]);

    $transactions = createRecurringReviewMonthlyGroup($user, $account, 'Netflix', 1699, 3);

    Livewire::actingAs($user)
        ->test(RecurringTransactionReview::class)
        ->call('findRecurring');

    $suggestion = AnalysisSuggestion::query()
        ->where('user_id', $user->id)
        ->ofType(SuggestionType::RecurringTransaction)
        ->pending()
        ->firstOrFail();

    Livewire::actingAs($user)
        ->test(RecurringTransactionReview::class)
        ->call('accept', $suggestion->id);

    $planned = PlannedTransaction::query()
        ->where('user_id', $user->id)
        ->first();

    expect($planned)->not->toBeNull()
        ->and($planned->is_active)->toBeTrue()
        ->and($planned->amount)->toBe(1699)
        ->and($planned->frequency)->toBe(RecurrenceFrequency::EveryMonth)
        ->and($planned->account_id)->toBe($account->id)
        ->and($planned->direction)->toBe(TransactionDirection::Debit)
        ->and($suggestion->fresh()->status)->toBe(SuggestionStatus::Accepted);

    $transactions->each(fn (Transaction $transaction) => expect($transaction->fresh()->planned_transaction_id)->toBe($planned->id));
});

test('accept with a category re-categorises every transaction of the merchant, not just the matched ones, and nobody elses', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $user->update(['primary_account_id' => $account->id]);
    $category = Category::factory()->create(['name' => 'Entertainment']);

    createRecurringReviewMonthlyGroup($user, $account, 'Netflix', 1699, 3);
    $unmatched = createRecurringReviewTransaction($user, Account::factory()->for($user)->create(), [
        'merchant_name' => 'Netflix',
        'amount' => 1699,
        'post_date' => '2026-03-02',
        'direction' => TransactionDirection::Debit,
    ]);

    $other = User::factory()->create();
    $otherAccount = Account::factory()->for($other)->create();
    $othersTransaction = createRecurringReviewTransaction($other, $otherAccount, [
        'merchant_name' => 'Netflix',
        'amount' => 1699,
        'direction' => TransactionDirection::Debit,
    ]);

    Livewire::actingAs($user)->test(RecurringTransactionReview::class)->call('findRecurring');

    $suggestion = AnalysisSuggestion::query()
        ->where('user_id', $user->id)
        ->ofType(SuggestionType::RecurringTransaction)
        ->pending()
        ->firstOrFail();

    expect($suggestion->payload['matched_transaction_ids'])->not->toContain($unmatched->id);

    Livewire::actingAs($user)
        ->test(RecurringTransactionReview::class)
        ->set('recurringCategories.'.$suggestion->id, $category->id)
        ->call('accept', $suggestion->id);

    expect($unmatched->fresh()->category_id)->toBe($category->id)
        ->and($unmatched->fresh()->category_source)->toBe(CategorySource::Manual)
        ->and($othersTransaction->fresh()->category_id)->toBeNull();
});

test('accept confirms the merchants payee so its rule and tag exist', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $user->update(['primary_account_id' => $account->id]);
    $category = Category::factory()->create(['name' => 'Entertainment']);

    $transactions = createRecurringReviewMonthlyGroup($user, $account, 'Netflix', 1699, 3);
    $payee = Payee::factory()->create([
        'user_id' => $user->id,
        'merchant_key' => $transactions->first()->merchant_key,
        'merchant_name' => 'Netflix',
    ]);

    Livewire::actingAs($user)->test(RecurringTransactionReview::class)->call('findRecurring');

    $suggestion = AnalysisSuggestion::query()
        ->where('user_id', $user->id)
        ->ofType(SuggestionType::RecurringTransaction)
        ->pending()
        ->firstOrFail();

    Livewire::actingAs($user)
        ->test(RecurringTransactionReview::class)
        ->set('recurringCategories.'.$suggestion->id, $category->id)
        ->call('accept', $suggestion->id);

    expect($payee->fresh()->status)->toBe(PayeeStatus::Confirmed)
        ->and($payee->fresh()->user_rule_id)->not->toBeNull();
});

test('dismiss rejects a pending recurring suggestion', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $user->update(['primary_account_id' => $account->id]);

    createRecurringReviewMonthlyGroup($user, $account, 'Netflix', 1699, 3);

    Livewire::actingAs($user)
        ->test(RecurringTransactionReview::class)
        ->call('findRecurring');

    $suggestion = AnalysisSuggestion::query()
        ->where('user_id', $user->id)
        ->ofType(SuggestionType::RecurringTransaction)
        ->pending()
        ->firstOrFail();

    Livewire::actingAs($user)
        ->test(RecurringTransactionReview::class)
        ->call('dismiss', $suggestion->id);

    expect($suggestion->fresh()->status)->toBe(SuggestionStatus::Rejected)
        ->and($suggestion->fresh()->resolved_at)->not->toBeNull();
});

test('accepted recurring patterns are not suggested again on a later scan', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $user->update(['primary_account_id' => $account->id]);

    createRecurringReviewMonthlyGroup($user, $account, 'Netflix', 1699, 3);

    Livewire::actingAs($user)
        ->test(RecurringTransactionReview::class)
        ->call('findRecurring');

    $suggestion = AnalysisSuggestion::query()
        ->where('user_id', $user->id)
        ->ofType(SuggestionType::RecurringTransaction)
        ->pending()
        ->firstOrFail();

    Livewire::actingAs($user)
        ->test(RecurringTransactionReview::class)
        ->call('accept', $suggestion->id)
        ->call('findRecurring');

    $suggestions = AnalysisSuggestion::query()
        ->where('user_id', $user->id)
        ->ofType(SuggestionType::RecurringTransaction)
        ->get();

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions->first()->status)->toBe(SuggestionStatus::Accepted)
        ->and(AnalysisSuggestion::query()
            ->where('user_id', $user->id)
            ->ofType(SuggestionType::RecurringTransaction)
            ->pending()
            ->count())->toBe(0);
});

test('rescanning without resolving supersedes the prior pending suggestion instead of duplicating it', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $user->update(['primary_account_id' => $account->id]);

    createRecurringReviewMonthlyGroup($user, $account, 'Netflix', 1699, 3);

    Livewire::actingAs($user)
        ->test(RecurringTransactionReview::class)
        ->call('findRecurring')
        ->call('findRecurring');

    expect(AnalysisSuggestion::query()
        ->where('user_id', $user->id)
        ->ofType(SuggestionType::RecurringTransaction)
        ->pending()
        ->count())->toBe(1)
        ->and(AnalysisSuggestion::query()
            ->where('user_id', $user->id)
            ->ofType(SuggestionType::RecurringTransaction)
            ->where('status', SuggestionStatus::Superseded)
            ->count())->toBe(1);
});

test('recurringCategories does not retain superseded suggestion keys across scans', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $user->update(['primary_account_id' => $account->id]);

    createRecurringReviewMonthlyGroup($user, $account, 'Netflix', 1699, 3);

    $component = Livewire::actingAs($user)
        ->test(RecurringTransactionReview::class)
        ->call('findRecurring');

    $firstKeys = array_keys($component->get('recurringCategories'));

    $component->call('findRecurring');

    $secondKeys = array_keys($component->get('recurringCategories'));

    expect($secondKeys)->toHaveCount(1)
        ->and(array_intersect($firstKeys, $secondKeys))->toBeEmpty();
});
