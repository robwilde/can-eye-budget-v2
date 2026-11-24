<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Enums\TransactionDirection;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineRun;
use App\Models\PlannedMatchRejection;
use App\Models\PlannedOccurrence;
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PlannedTransactionMatcher;
use App\Services\ReconciliationPolicy;
use App\Services\Recurring\PlannedPaymentMatcher;
use App\Services\TransactionIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-03-17 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->run = PipelineRun::factory()->for($this->user)->create();
});

function matchPayer(object $test, int $cents, string $postDate, array $attributes = []): Transaction
{
    return Transaction::factory()->for($test->user)->for($test->account)->create([
        'amount' => -$cents, 'direction' => TransactionDirection::Debit, 'description' => 'ACME POWER', 'post_date' => $postDate, 'planned_transaction_id' => null, ...$attributes,
    ]);
}

function matchPlan(object $test, int $cents, string $due, ?string $merchantKey, array $attributes = []): PlannedOccurrence
{
    $plan = PlannedTransaction::factory()->for($test->user)->for($test->account)->create([
        'description' => 'Acme Power', 'amount' => $cents, 'direction' => TransactionDirection::Debit, 'merchant_key' => $merchantKey,
        'start_date' => '2026-01-10', 'until_date' => null, ...$attributes,
    ]);

    return PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => $due, 'due_date' => $due]);
}

function matchRun(object $test): array
{
    return app(PlannedPaymentMatcher::class)->matchForUser($test->user, $test->run);
}

function matchSuggestions(): Illuminate\Database\Eloquent\Collection
{
    return AnalysisSuggestion::query()->ofType(SuggestionType::PlannedPaymentMatch)->get();
}

it('auto-links a confident posted match and closes the occurrence', function () {
    $tx = matchPayer($this, 10000, '2026-03-12');
    $occurrence = matchPlan($this, 10000, '2026-03-10', $tx->merchant_key);

    expect(matchRun($this))->toMatchArray(['linked' => 1, 'suggested' => 0]);

    expect($occurrence->fresh()->status)->toBe(OccurrenceStatus::Paid)
        ->and($tx->fresh()->planned_transaction_id)->toBe($occurrence->planned_transaction_id)
        ->and(PlannedPayment::query()->sole()->amount)->toBe(10000);
});

it('turns a mid-confidence match into a suggestion', function () {
    $tx = matchPayer($this, 10400, '2026-03-17', ['description' => 'ACME POWER BILL SYDNEY']);
    $occurrence = matchPlan($this, 10000, '2026-03-10', null);

    expect(ReconciliationPolicy::score($occurrence, $tx, $this->user->today())->total())->toBeBetween(0.60, 0.85);
    expect(matchRun($this))->toMatchArray(['linked' => 0, 'suggested' => 1]);

    $suggestion = matchSuggestions()->sole();

    expect($suggestion->status)->toBe(SuggestionStatus::Pending)
        ->and($suggestion->payload)->toMatchArray(['transaction_id' => $tx->id, 'planned_occurrence_id' => $occurrence->id])
        ->and(PlannedPayment::query()->count())->toBe(0)
        ->and($tx->fresh()->planned_transaction_id)->toBeNull();
});

it('only suggests a match for a pending transaction', function () {
    $tx = matchPayer($this, 10000, '2026-03-12', ['status' => App\Enums\TransactionStatus::Pending]);
    $occurrence = matchPlan($this, 10000, '2026-03-10', $tx->merchant_key);

    expect(matchRun($this))->toMatchArray(['linked' => 0, 'suggested' => 1]);

    expect($occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled)
        ->and(matchSuggestions())->toHaveCount(1)
        ->and(PlannedPayment::query()->count())->toBe(0);
});

it('suggests instead of linking when two occurrences score close', function () {
    $tx = matchPayer($this, 10000, '2026-03-11');
    $first = matchPlan($this, 10000, '2026-03-10', $tx->merchant_key);
    PlannedOccurrence::factory()->for($first->plannedTransaction, 'plannedTransaction')->create(['original_date' => '2026-03-12', 'due_date' => '2026-03-12']);

    expect(matchRun($this))->toMatchArray(['linked' => 0, 'suggested' => 1]);

    expect(PlannedPayment::query()->count())->toBe(0)->and($tx->fresh()->planned_transaction_id)->toBeNull();
});

it('never suggests a mid-confidence income match', function () {
    $tx = matchPayer($this, 208000, '2026-03-17', ['description' => 'ACME POWER PTY', 'amount' => 208000, 'direction' => TransactionDirection::Credit]);
    $occurrence = matchPlan($this, 200000, '2026-03-10', null, ['direction' => TransactionDirection::Credit]);

    expect(ReconciliationPolicy::score($occurrence, $tx, $this->user->today())->total())->toBeBetween(0.60, 0.85);
    expect(matchRun($this))->toMatchArray(['linked' => 0, 'suggested' => 0]);

    expect(matchSuggestions())->toHaveCount(0)->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('links a confident income match', function () {
    $tx = matchPayer($this, 200000, '2026-03-12', ['amount' => 200000, 'direction' => TransactionDirection::Credit]);
    $occurrence = matchPlan($this, 200000, '2026-03-10', $tx->merchant_key, ['direction' => TransactionDirection::Credit]);

    expect(matchRun($this)['linked'])->toBe(1)->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Paid);
});

it('never proposes a rejected transaction for that plan again', function () {
    $tx = matchPayer($this, 10000, '2026-03-12');
    $occurrence = matchPlan($this, 10000, '2026-03-10', $tx->merchant_key);
    PlannedMatchRejection::query()->create(['user_id' => $this->user->id, 'planned_transaction_id' => $occurrence->planned_transaction_id, 'transaction_id' => $tx->id]);

    expect(matchRun($this))->toMatchArray(['linked' => 0, 'suggested' => 0]);

    expect($tx->fresh()->planned_transaction_id)->toBeNull()->and(matchSuggestions())->toHaveCount(0);
});

it('never matches a transaction to another users plan', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->for($other)->create();
    $tx = matchPayer($this, 10000, '2026-03-12');
    $plan = PlannedTransaction::factory()->for($other)->for($otherAccount)->create(['description' => 'Acme Power', 'amount' => 10000, 'direction' => TransactionDirection::Debit, 'merchant_key' => $tx->merchant_key, 'start_date' => '2026-01-10']);
    $theirs = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-03-10', 'due_date' => '2026-03-10']);

    expect(matchRun($this))->toMatchArray(['linked' => 0, 'suggested' => 0]);

    expect($theirs->fresh()->status)->toBe(OccurrenceStatus::Scheduled)->and($tx->fresh()->planned_transaction_id)->toBeNull();
});

it('uses each transaction and each occurrence at most once in a run', function () {
    $first = matchPayer($this, 10000, '2026-03-12');
    $second = matchPayer($this, 10000, '2026-03-12');
    $occurrence = matchPlan($this, 10000, '2026-03-10', $first->merchant_key);

    $result = matchRun($this);

    expect($result['linked'] + $result['suggested'])->toBe(1)
        ->and(Transaction::query()->whereNotNull('planned_transaction_id')->count())->toBeLessThanOrEqual(1)
        ->and(PlannedPayment::query()->where('planned_occurrence_id', $occurrence->id)->count())->toBeLessThanOrEqual(1);
});

it('does not score a transaction against a plan on another account, in another direction or outside tolerance', function (array $transaction) {
    $tx = matchPayer($this, 10000, '2026-03-12', $transaction);
    $occurrence = matchPlan($this, 10000, '2026-03-10', $tx->merchant_key);

    expect(ReconciliationPolicy::score($occurrence, $tx->refresh(), $this->user->today()))->toBeNull();
})->with([
    'another direction' => [['direction' => TransactionDirection::Credit, 'amount' => 10000]],
    'outside tolerance' => [['amount' => -12000]],
]);

it('scores identity, amount, date and account', function () {
    $tx = matchPayer($this, 10000, '2026-03-10');
    $occurrence = matchPlan($this, 10000, '2026-03-10', $tx->merchant_key);

    $score = ReconciliationPolicy::score($occurrence, $tx, $this->user->today());

    expect($score->identity)->toBe(0.40)->and($score->amount)->toBe(0.30)->and($score->date)->toBe(0.20)->and($score->account)->toBe(0.10)->and($score->total())->toBe(1.0);
});

it('keeps an overdue occurrence matchable up to today', function () {
    $tx = matchPayer($this, 10000, '2026-03-17');
    $occurrence = matchPlan($this, 10000, '2026-02-20', $tx->merchant_key);

    expect(ReconciliationPolicy::score($occurrence, $tx, $this->user->today()))->not->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-02-25 12:00:00', 'UTC'));
    $early = matchPayer($this, 10000, '2026-03-17');

    expect(ReconciliationPolicy::score($occurrence, $early, CarbonImmutable::parse('2026-02-25')))->toBeNull();
});

it('matches a recurring transfer on its outflow leg', function () {
    $savings = Account::factory()->for($this->user)->create();
    $out = matchPayer($this, 50000, '2026-03-12', ['description' => 'SAVINGS TOP UP']);
    $in = Transaction::factory()->for($this->user)->for($savings)->create(['amount' => 50000, 'direction' => TransactionDirection::Credit, 'description' => 'SAVINGS TOP UP', 'post_date' => '2026-03-12', 'transfer_pair_id' => $out->id]);
    $out->update(['transfer_pair_id' => $in->id]);
    $occurrence = matchPlan($this, 50000, '2026-03-10', $out->merchant_key, ['transfer_to_account_id' => $savings->id, 'description' => 'Savings top up']);

    expect(matchRun($this)['linked'])->toBe(1);

    expect($out->fresh()->planned_transaction_id)->toBe($occurrence->planned_transaction_id)->and($in->fresh()->planned_transaction_id)->toBeNull();
});

it('settles a pending suggestion in the same transaction as the link', function () {
    $tx = matchPayer($this, 10000, '2026-03-12', ['status' => App\Enums\TransactionStatus::Pending]);
    $occurrence = matchPlan($this, 10000, '2026-03-10', $tx->merchant_key);
    matchRun($this);

    app(App\Services\ReconciliationMatcher::class)->link($tx->fresh(), $occurrence->plannedTransaction);

    expect(matchSuggestions()->sole()->status)->toBe(SuggestionStatus::Superseded);
});

it('records the payment when the ingestor links a transaction outside a pipeline run', function () {
    $occurrence = matchPlan($this, 10000, '2026-03-10', null, ['frequency' => App\Enums\RecurrenceFrequency::EveryMonth]);
    $tx = Transaction::factory()->for($this->user)->for($this->account)->make(['amount' => -10000, 'direction' => TransactionDirection::Debit, 'post_date' => '2026-03-11', 'planned_transaction_id' => null]);

    app(TransactionIngestor::class)->ingest($tx);

    expect($tx->fresh()->planned_transaction_id)->toBe($occurrence->planned_transaction_id)
        ->and(PlannedPayment::query()->where('transaction_id', $tx->id)->sole()->amount)->toBe(10000)
        ->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Paid);
});

it('records the payment when the backstop matcher links a transaction', function () {
    $occurrence = matchPlan($this, 10000, '2026-03-10', null, ['frequency' => App\Enums\RecurrenceFrequency::EveryMonth]);
    $tx = matchPayer($this, 10000, '2026-03-11');

    expect(app(PlannedTransactionMatcher::class)->matchForUser($this->user))->toBe(1);

    expect(PlannedPayment::query()->where('transaction_id', $tx->id)->sole()->planned_occurrence_id)->toBe($occurrence->id);
});

it('leaves the transaction unlinked and suggests it when the payment is refused', function () {
    $tx = matchPayer($this, 10000, '2026-03-12', ['currency' => 'USD']);
    $occurrence = matchPlan($this, 10000, '2026-03-10', $tx->merchant_key);

    expect(matchRun($this))->toMatchArray(['linked' => 0, 'suggested' => 1]);

    expect($tx->fresh()->planned_transaction_id)->toBeNull()->and(PlannedPayment::query()->count())->toBe(0)->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('locks transactions before plans and occurrences when the stage links a match', function () {
    $tx = matchPayer($this, 10000, '2026-03-12');
    matchPlan($this, 10000, '2026-03-10', $tx->merchant_key);

    $locks = lockLog(fn () => matchRun($this));

    expect(array_column($locks, 'table'))->toContain('planned_occurrences');
    expectTransactionsFirst($locks);
});

it('scores against what a variable bill is expected to cost from its payment history', function () {
    $tx = matchPayer($this, 17500, '2026-03-12');
    $occurrence = matchPlan($this, 10000, '2026-03-10', $tx->merchant_key, ['amount_strategy' => App\Enums\PlanAmountStrategy::LastPaid]);
    $earlier = PlannedOccurrence::factory()->paid()->for($occurrence->plannedTransaction, 'plannedTransaction')->create(['original_date' => '2026-02-10', 'due_date' => '2026-02-10']);
    PlannedPayment::factory()->create(['planned_occurrence_id' => $earlier->id, 'transaction_id' => null, 'amount' => 18000]);

    expect(matchRun($this)['linked'])->toBe(1)->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Paid);
});

it('links on a lead of exactly fifteen points', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-14 12:00:00', 'UTC'));
    $tx = matchPayer($this, 10000, '2026-03-13');
    $near = matchPlan($this, 10000, '2026-03-11', $tx->merchant_key, ['frequency' => App\Enums\RecurrenceFrequency::EveryMonth]);
    matchPlan($this, 10000, '2026-03-07', null, ['frequency' => App\Enums\RecurrenceFrequency::EveryMonth]);

    expect(matchRun($this)['linked'])->toBe(1)->and($near->fresh()->status)->toBe(OccurrenceStatus::Paid);
});

it('does not hold an occurrence taken earlier in the run against the next transaction', function () {
    $first = matchPayer($this, 10000, '2026-03-03');
    $second = matchPayer($this, 10000, '2026-03-10');
    $plan = matchPlan($this, 10000, '2026-03-03', $first->merchant_key, ['frequency' => App\Enums\RecurrenceFrequency::EveryWeek]);
    foreach (['2026-03-10', '2026-03-17'] as $date) {
        PlannedOccurrence::factory()->for($plan->plannedTransaction, 'plannedTransaction')->create(['original_date' => $date, 'due_date' => $date]);
    }

    expect(matchRun($this))->toMatchArray(['linked' => 2, 'suggested' => 0]);
});

it('notes the claim for after the batch when a matcher links inside one', function () {
    $tx = matchPayer($this, 10000, '2026-03-11');
    $occurrence = matchPlan($this, 10000, '2026-03-10', null, ['frequency' => App\Enums\RecurrenceFrequency::EveryMonth]);
    $plan = $occurrence->plannedTransaction;

    $locks = lockLog(function () use ($tx, $plan): void {
        app(App\Services\Recurring\PaymentSettlementDeferral::class)->deferring(function () use ($tx, $plan): void {
            DB::transaction(function () use ($tx, $plan): void {
                app(PlannedPaymentMatcher::class)->linkToPlan($tx, $plan);

                expect(PlannedPayment::query()->count())->toBe(0);
            });
        });
    });

    expect(PlannedPayment::query()->where('transaction_id', $tx->id)->count())->toBe(1);
    expectBatchLockedFirst($locks, [$tx->id]);
});

it('keeps the planned payment matches out of the analysis suggestions list', function () {
    AnalysisSuggestion::query()->create(['user_id' => $this->user->id, 'pipeline_run_id' => $this->run->id, 'type' => SuggestionType::PlannedPaymentMatch, 'status' => SuggestionStatus::Pending, 'payload' => []]);

    Livewire\Livewire::actingAs($this->user)->test(App\Livewire\AnalysisSuggestions::class)->assertDontSee('Analysis Suggestions');
});

it('considers a transaction posted on the last day of the lookahead', function () {
    $tx = matchPayer($this, 10000, '2026-03-20');
    $occurrence = matchPlan($this, 10000, '2026-03-20', $tx->merchant_key, ['frequency' => App\Enums\RecurrenceFrequency::EveryMonth]);

    expect(matchRun($this)['linked'])->toBe(1)->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Paid);
});
