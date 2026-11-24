<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\PayeeStatus;
use App\Enums\PlannedTransactionStatus;
use App\Enums\PlannedTransactionType;
use App\Enums\PlanOrigin;
use App\Enums\RecurrenceFrequency;
use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Enums\TransactionDirection;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Jobs\RunTransactionAnalysisJob;
use App\Livewire\RecurringTransactionReview;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\Category;
use App\Models\Payee;
use App\Models\PipelineRun;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Recurring\PlanTypeClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
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
        ->and($planned->status)->toBe(PlannedTransactionStatus::Active)
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
    $category = Category::factory()->for($user)->create(['name' => 'Entertainment']);

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
    $category = Category::factory()->for($user)->create(['name' => 'Entertainment']);

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

test('accept records a recent pick only for a hand-picked category', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $user->update(['primary_account_id' => $account->id]);
    $category = Category::factory()->for($user)->create(['name' => 'Entertainment']);

    createRecurringReviewMonthlyGroup($user, $account, 'Netflix', 1699, 3);
    Livewire::actingAs($user)->test(RecurringTransactionReview::class)->call('findRecurring');
    $suggestion = AnalysisSuggestion::query()->where('user_id', $user->id)->ofType(SuggestionType::RecurringTransaction)->pending()->firstOrFail();

    Livewire::actingAs($user)
        ->test(RecurringTransactionReview::class)
        ->set('recurringCategories.'.$suggestion->id, $category->id)
        ->call('accept', $suggestion->id);

    expect($user->fresh()->preferences->recentCategoryIds)->toBe([$category->id]);
});

test('accept without choosing a category records no recent pick', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $user->update(['primary_account_id' => $account->id]);

    createRecurringReviewMonthlyGroup($user, $account, 'Netflix', 1699, 3);
    Livewire::actingAs($user)->test(RecurringTransactionReview::class)->call('findRecurring');
    $suggestion = AnalysisSuggestion::query()->where('user_id', $user->id)->ofType(SuggestionType::RecurringTransaction)->pending()->firstOrFail();

    Livewire::actingAs($user)->test(RecurringTransactionReview::class)->call('accept', $suggestion->id);

    expect($user->fresh()->preferences->recentCategoryIds)->toBe([]);
});

function detectionWorld(?string $accountState = null): array
{
    $user = User::factory()->create();
    $account = match ($accountState) {
        'investment' => Account::factory()->for($user)->investment()->create(),
        'card' => Account::factory()->for($user)->creditCard()->create(),
        default => Account::factory()->for($user)->create(),
    };
    $user->update(['primary_account_id' => $account->id]);

    return [$user, $account];
}

function detectionScan(User $user)
{
    return Livewire::actingAs($user)->test(RecurringTransactionReview::class)->call('findRecurring');
}

function detectionPending(User $user): int
{
    return AnalysisSuggestion::query()->where('user_id', $user->id)->ofType(SuggestionType::RecurringTransaction)->pending()->count();
}

it('never re-suggests a dismissed pattern', function () {
    [$user, $account] = detectionWorld();
    createRecurringReviewMonthlyGroup($user, $account, 'Netflix', 1699, 3);
    AnalysisSuggestion::query()->create([
        'user_id' => $user->id, 'pipeline_run_id' => PipelineRun::factory()->for($user)->create()->id, 'type' => SuggestionType::RecurringTransaction,
        'status' => SuggestionStatus::Rejected, 'resolved_at' => now()->subDays(120), 'payload' => ['description' => 'NETFLIX', 'account_id' => $account->id],
    ]);

    detectionScan($user);

    expect(detectionPending($user))->toBe(0);
});

it('claims an existing plan instead of suggesting a duplicate', function (PlannedTransactionStatus $status) {
    [$user, $account] = detectionWorld();
    createRecurringReviewMonthlyGroup($user, $account, 'Netflix', 1699, 3);
    PlannedTransaction::factory()->for($user)->for($account)->create(['description' => 'Netflix', 'amount' => 1699, 'direction' => TransactionDirection::Debit, 'frequency' => RecurrenceFrequency::EveryMonth, 'status' => $status]);

    detectionScan($user);

    expect(detectionPending($user))->toBe(0)->and(PlannedTransaction::query()->count())->toBe(1);
})->with([PlannedTransactionStatus::Active, PlannedTransactionStatus::Inactive, PlannedTransactionStatus::Ended]);

it('claims the existing plan whatever its cadence when the amount is within tolerance', function () {
    [$user, $account] = detectionWorld();
    createRecurringReviewMonthlyGroup($user, $account, 'Netflix', 1699, 3);
    PlannedTransaction::factory()->for($user)->for($account)->create(['description' => 'Netflix', 'amount' => 1750, 'direction' => TransactionDirection::Debit, 'frequency' => RecurrenceFrequency::EveryWeek]);

    detectionScan($user);

    expect(detectionPending($user))->toBe(0);
});

it('does not recreate an ended plan', function () {
    [$user, $account] = detectionWorld();
    createRecurringReviewMonthlyGroup($user, $account, 'Netflix', 1699, 3);
    $ended = PlannedTransaction::factory()->for($user)->for($account)->ended()->create(['description' => 'Netflix', 'amount' => 1699, 'direction' => TransactionDirection::Debit, 'frequency' => RecurrenceFrequency::EveryMonth]);

    detectionScan($user);

    expect(detectionPending($user))->toBe(0)->and(PlannedTransaction::query()->count())->toBe(1)->and($ended->fresh()->status)->toBe(PlannedTransactionStatus::Ended);
});

it('never scans investment accounts', function () {
    [$user, $account] = detectionWorld('investment');
    createRecurringReviewMonthlyGroup($user, $account, 'Vanguard Dividend', 10000, 3);

    detectionScan($user);

    expect(detectionPending($user))->toBe(0);
});

it('never scans transfer legs', function () {
    [$user, $account] = detectionWorld();
    createRecurringReviewMonthlyGroup($user, $account, 'Savings Top Up', 50000, 3)->each(fn (Transaction $row) => $row->update(['transfer_pair_id' => Transaction::factory()->for($user)->for(Account::factory()->for($user))->create()->id]));

    detectionScan($user);

    expect(detectionPending($user))->toBe(0);
});

it('refuses a scan while analysis is running for the user', function () {
    [$user, $account] = detectionWorld();
    createRecurringReviewMonthlyGroup($user, $account, 'Netflix', 1699, 3);
    $lock = Cache::lock(RunTransactionAnalysisJob::lockKey($user->id), 60);
    $lock->get();

    detectionScan($user)->assertDispatched('toast-show', slots: ['text' => 'A scan is already running. Try again in a moment.'], dataset: ['variant' => 'warning']);

    expect(detectionPending($user))->toBe(0)->and(PipelineRun::query()->where('user_id', $user->id)->count())->toBe(0);

    $lock->release();
    detectionScan($user);

    expect(detectionPending($user))->toBe(1);
});

it('takes the same lock key as the analysis job middleware', function () {
    $user = User::factory()->create();

    expect(RunTransactionAnalysisJob::lockKey($user->id))->toBe(new WithoutOverlapping("analysis-user-{$user->id}")->getLockKey(new RunTransactionAnalysisJob($user)));
});

it('types an accepted streaming pattern as an autopay subscription', function () {
    [$user, $account] = detectionWorld();
    createRecurringReviewMonthlyGroup($user, $account, 'Netflix', 1699, 3);
    detectionScan($user);

    Livewire::actingAs($user)->test(RecurringTransactionReview::class)->call('accept', AnalysisSuggestion::query()->where('user_id', $user->id)->firstOrFail()->id);

    $plan = PlannedTransaction::query()->where('user_id', $user->id)->sole();

    expect($plan->type)->toBe(PlannedTransactionType::Subscription)->and($plan->autopay)->toBeTrue()->and($plan->origin)->toBe(PlanOrigin::Detected);
});

it('classifies names, direction and flat card charges', function (string $name, TransactionDirection $direction, bool $flat, PlannedTransactionType $type, bool $autopay) {
    $classifier = new PlanTypeClassifier;
    $result = $classifier->classify($name, $direction, $flat);

    expect($result)->toBe($type)->and($classifier->defaultAutopay($result))->toBe($autopay);
})->with([
    'afterpay' => ['AFTERPAY INSTALMENT', TransactionDirection::Debit, false, PlannedTransactionType::Installment, true],
    'spotify' => ['Spotify', TransactionDirection::Debit, false, PlannedTransactionType::Subscription, true],
    'energy' => ['Origin Energy', TransactionDirection::Debit, false, PlannedTransactionType::Bill, false],
    'rent' => ['Ray White Rent', TransactionDirection::Debit, false, PlannedTransactionType::Bill, false],
    'telco' => ['Telstra', TransactionDirection::Debit, false, PlannedTransactionType::Bill, false],
    'flat card' => ['Mystery Co', TransactionDirection::Debit, true, PlannedTransactionType::Subscription, true],
    'unknown' => ['Mystery Co', TransactionDirection::Debit, false, PlannedTransactionType::Bill, false],
    'income' => ['Netflix Refund', TransactionDirection::Credit, false, PlannedTransactionType::Income, false],
    'stan alone' => ['Stan', TransactionDirection::Debit, false, PlannedTransactionType::Subscription, true],
    'word prefix is not a match' => ['Hummingbird Coffee', TransactionDirection::Debit, false, PlannedTransactionType::Bill, false],
    'substring is not a match' => ['Stanley Hardware', TransactionDirection::Debit, false, PlannedTransactionType::Bill, false],
]);

it('types a flat identical card charge of 150 or less as a subscription', function () {
    [$user, $account] = detectionWorld('card');
    createRecurringReviewMonthlyGroup($user, $account, 'Mystery Co', 4500, 3);
    detectionScan($user);

    Livewire::actingAs($user)->test(RecurringTransactionReview::class)->call('accept', AnalysisSuggestion::query()->where('user_id', $user->id)->firstOrFail()->id);

    expect(PlannedTransaction::query()->where('user_id', $user->id)->sole()->type)->toBe(PlannedTransactionType::Subscription);
});

it('never claims another users plan', function () {
    [$user, $account] = detectionWorld();
    $other = User::factory()->create();
    createRecurringReviewMonthlyGroup($user, $account, 'Netflix', 1699, 3);
    PlannedTransaction::factory()->for($other)->for($account)->create(['description' => 'Netflix', 'amount' => 1699, 'direction' => TransactionDirection::Debit, 'frequency' => RecurrenceFrequency::EveryMonth]);

    detectionScan($user);

    expect(detectionPending($user))->toBe(1);
});

it('refreshes the due day of an unpinned monthly plan and never its amount or status', function () {
    [$user, $account] = detectionWorld();
    createRecurringReviewMonthlyGroup($user, $account, 'Netflix', 1699, 3);
    $plan = PlannedTransaction::factory()->for($user)->for($account)->create(['description' => 'Netflix', 'amount' => 1750, 'direction' => TransactionDirection::Debit, 'frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2025-12-01', 'status' => PlannedTransactionStatus::Inactive]);

    detectionScan($user);

    expect($plan->fresh()->days_of_month)->toBe([15])->and($plan->fresh()->amount)->toBe(1750)->and($plan->fresh()->status)->toBe(PlannedTransactionStatus::Inactive);
});

it('leaves a pinned or ended plan schedule alone when it is re-detected', function () {
    [$user, $account] = detectionWorld();
    createRecurringReviewMonthlyGroup($user, $account, 'Netflix', 1699, 3);
    $pinned = PlannedTransaction::factory()->for($user)->for($account)->create(['description' => 'Netflix', 'amount' => 1699, 'direction' => TransactionDirection::Debit, 'frequency' => RecurrenceFrequency::EveryMonth, 'schedule_pinned' => true]);

    detectionScan($user);
    $pinned->update(['schedule_pinned' => false, 'status' => PlannedTransactionStatus::Ended]);
    detectionScan($user);

    expect($pinned->fresh()->days_of_month)->toBeNull();
});

function earlyStartSuggestion(User $user, Account $account): AnalysisSuggestion
{
    return AnalysisSuggestion::query()->create([
        'user_id' => $user->id, 'pipeline_run_id' => PipelineRun::factory()->for($user)->create()->id, 'type' => SuggestionType::RecurringTransaction, 'status' => SuggestionStatus::Pending,
        'payload' => ['description' => 'NETFLIX', 'clean_description' => 'Netflix', 'amount' => 1699, 'direction' => 'debit', 'frequency' => 'every-month', 'account_id' => $account->id, 'category_id' => null, 'matched_transaction_ids' => [], 'start_date' => '2025-06-15', 'confidence_score' => 0.9],
    ]);
}

it('backfills six months of history on accept and not on accepting again', function () {
    [$user, $account] = detectionWorld();
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    $suggestion = earlyStartSuggestion($user, $account);

    Livewire::actingAs($user)->test(RecurringTransactionReview::class)->call('accept', $suggestion->id);

    $plan = PlannedTransaction::query()->where('user_id', $user->id)->sole();
    $dates = PlannedOccurrence::query()->where('planned_transaction_id', $plan->id)->orderBy('original_date')->pluck('original_date')->map->toDateString()->all();

    expect($dates)->toBe(['2025-10-15', '2025-11-15', '2025-12-15', '2026-01-15', '2026-02-15', '2026-03-15']);

    PlannedOccurrence::query()->where('planned_transaction_id', $plan->id)->delete();
    Livewire::actingAs($user)->test(RecurringTransactionReview::class)->call('accept', $suggestion->id);

    expect(PlannedOccurrence::query()->where('planned_transaction_id', $plan->id)->count())->toBe(0)->and(PlannedTransaction::query()->count())->toBe(1);
});

it('accepts without a backfill while analysis holds the lock', function () {
    [$user, $account] = detectionWorld();
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    $suggestion = earlyStartSuggestion($user, $account);
    $lock = Cache::lock(RunTransactionAnalysisJob::lockKey($user->id), 60);
    $lock->get();

    Livewire::actingAs($user)->test(RecurringTransactionReview::class)->call('accept', $suggestion->id);

    $lock->release();

    expect($suggestion->fresh()->status)->toBe(SuggestionStatus::Accepted)->and(PlannedOccurrence::query()->count())->toBe(0);
});

it('reports how many new suggestions a scan created', function () {
    [$user, $account] = detectionWorld();
    createRecurringReviewMonthlyGroup($user, $account, 'Netflix', 1699, 3);

    detectionScan($user)->assertSee('Netflix')->assertDispatched('toast-show', slots: ['text' => 'Found 1 new recurring suggestion'], dataset: ['variant' => 'success']);

    expect(detectionPending($user))->toBe(1);
});

it('refreshes the due day from the latest charge even when the pattern also matches an accepted suggestion', function () {
    [$user, $account] = detectionWorld();
    $plan = PlannedTransaction::factory()->for($user)->for($account)->create(['description' => 'Netflix', 'amount' => 1699, 'direction' => TransactionDirection::Debit, 'frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2026-01-03', 'origin' => PlanOrigin::Detected]);
    AnalysisSuggestion::query()->create(['user_id' => $user->id, 'pipeline_run_id' => PipelineRun::factory()->for($user)->create()->id, 'type' => SuggestionType::RecurringTransaction, 'status' => SuggestionStatus::Accepted, 'resolved_at' => now(), 'payload' => ['description' => 'NETFLIX', 'account_id' => $account->id]]);
    $latest = createRecurringReviewTransaction($user, $account, ['merchant_name' => 'Netflix', 'amount' => 1699, 'post_date' => '2026-03-18', 'direction' => TransactionDirection::Debit]);
    $candidate = new App\DTOs\RecurringCandidate('NETFLIX', 'Netflix', 1699, TransactionDirection::Debit, RecurrenceFrequency::EveryMonth, $account->id, null, [$latest->id], CarbonImmutable::parse('2026-01-03'), 0.9);

    app(App\Services\Recurring\RecurringSuggestionWriter::class)->writeForRun($user, PipelineRun::factory()->for($user)->create(), collect([$candidate]));

    expect($plan->fresh()->days_of_month)->toBe([18]);

    $plan->refresh();
    app(App\Services\Recurring\RecurringSuggestionWriter::class)->writeForRun($user, PipelineRun::factory()->for($user)->create(), collect([$candidate]));

    expect($plan->fresh()->updated_at->equalTo($plan->updated_at))->toBeTrue();
});

it('accepts from the providers page suggestions with the same backfill', function () {
    [$user, $account] = detectionWorld();
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    $suggestion = earlyStartSuggestion($user, $account);

    Livewire::actingAs($user)->test(App\Livewire\AnalysisSuggestions::class)->call('acceptRecurringTransaction', $suggestion->id);

    expect(PlannedOccurrence::query()->count())->toBe(6);
});
