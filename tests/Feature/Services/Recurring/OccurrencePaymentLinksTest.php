<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\RecurrenceFrequency;
use App\Enums\SuggestionType;
use App\Enums\TransactionDirection;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\PlannedOccurrence;
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Recurring\OccurrencePaymentRecorder;
use App\Services\Recurring\PlannedOccurrenceGenerator;
use App\Services\RuleActionExecutor;
use App\Services\SuggestionApplier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-03-20 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->recorder = app(OccurrencePaymentRecorder::class);
});

function linkPlan(object $test, array $attributes = []): PlannedTransaction
{
    return PlannedTransaction::factory()->for($test->user)->for($test->account)->create(array_merge([
        'description' => 'Rent',
        'amount' => 2000,
        'direction' => TransactionDirection::Debit,
        'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2026-03-15',
        'amount_tolerance_bps' => 0,
    ], $attributes));
}

function linkOccurrence(PlannedTransaction $plan, string $date = '2026-03-15'): PlannedOccurrence
{
    return PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => $date, 'due_date' => $date]);
}

function linkTransaction(object $test, int $cents, ?PlannedTransaction $plan = null, string $date = '2026-03-15'): Transaction
{
    return Transaction::factory()->for($test->user)->for($test->account)->create([
        'amount' => -$cents,
        'direction' => TransactionDirection::Debit,
        'post_date' => $date,
        'planned_transaction_id' => $plan?->id,
    ]);
}

it('takes back the payment and reopens the occurrence when the transaction is unlinked', function () {
    $plan = linkPlan($this);
    $occurrence = linkOccurrence($plan);
    $transaction = linkTransaction($this, 2000, $plan);
    $this->recorder->attach($this->user, $transaction->id, $occurrence->id);
    expect($occurrence->fresh()->status)->toBe(OccurrenceStatus::Paid);

    $transaction->update(['planned_transaction_id' => null]);

    expect(PlannedPayment::query()->count())->toBe(0)
        ->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled)
        ->and($occurrence->fresh()->closed_at)->toBeNull();
});

it('takes back the payment when the transaction is moved to another plan', function () {
    $plan = linkPlan($this);
    $other = linkPlan($this, ['description' => 'Water']);
    $occurrence = linkOccurrence($plan);
    $transaction = linkTransaction($this, 500, $plan);
    $this->recorder->attach($this->user, $transaction->id, $occurrence->id);

    $transaction->update(['planned_transaction_id' => $other->id]);

    expect($occurrence->fresh()->paidCents())->toBe(0)
        ->and(PlannedPayment::query()->sole()->occurrence->planned_transaction_id)->toBe($other->id);
});

it('keeps the payment when something else about the transaction changes', function () {
    $plan = linkPlan($this);
    $occurrence = linkOccurrence($plan);
    $transaction = linkTransaction($this, 500, $plan);
    $this->recorder->attach($this->user, $transaction->id, $occurrence->id);

    $transaction->update(['notes' => 'checked']);

    expect(PlannedPayment::query()->count())->toBe(1);
});

it('takes back the payment and reopens the occurrence when the transaction is deleted', function () {
    $plan = linkPlan($this);
    $occurrence = linkOccurrence($plan);
    $transaction = linkTransaction($this, 2000, $plan);
    $this->recorder->attach($this->user, $transaction->id, $occurrence->id);

    $transaction->delete();

    expect(PlannedPayment::query()->count())->toBe(0)->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('takes back the payment when the transaction is force deleted', function () {
    $plan = linkPlan($this);
    $occurrence = linkOccurrence($plan);
    $transaction = linkTransaction($this, 2000, $plan);
    $this->recorder->attach($this->user, $transaction->id, $occurrence->id);

    $transaction->forceDelete();

    expect(PlannedPayment::query()->count())->toBe(0)->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('leaves a payment closed by hand closed and a payment without a transaction alone', function () {
    $plan = linkPlan($this);
    $manual = linkOccurrence($plan);
    $this->recorder->markPaid($this->user, $manual->id);
    $other = linkOccurrence($plan, '2026-04-15');
    $transaction = linkTransaction($this, 500, $plan, '2026-04-14');
    $this->recorder->attach($this->user, $transaction->id, $other->id);

    $transaction->delete();

    expect($manual->fresh()->status)->toBe(OccurrenceStatus::Paid)
        ->and(PlannedPayment::query()->whereNull('transaction_id')->count())->toBe(1)
        ->and(PlannedPayment::query()->count())->toBe(1);
});

it('moves the payments to the new version when the transaction is edited', function () {
    $plan = linkPlan($this);
    $occurrence = linkOccurrence($plan);
    $transaction = linkTransaction($this, 2000, $plan);
    $this->recorder->attach($this->user, $transaction->id, $occurrence->id);

    $child = $transaction->createChild(['notes' => 'edited']);

    expect(PlannedPayment::query()->sole()->transaction_id)->toBe($child->id)
        ->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Paid);

    $child->delete();

    expect(PlannedPayment::query()->sole()->transaction_id)->toBe($transaction->id)
        ->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Paid);
});

it('keeps an occurrence that has payments when the history is rebuilt', function () {
    $plan = linkPlan($this);
    $paid = linkOccurrence($plan, '2026-01-15');
    $plain = linkOccurrence($plan, '2026-02-15');
    $this->recorder->record($this->user, $paid->id, 500);

    app(PlannedOccurrenceGenerator::class)->rebuildHistory($this->user);

    expect(PlannedOccurrence::query()->find($paid->id))->not->toBeNull()
        ->and(PlannedOccurrence::query()->find($plain->id))->toBeNull();
});

it('records a payment on the occurrence nearest a transaction that is claimed for a plan', function () {
    $plan = linkPlan($this);
    $transaction = linkTransaction($this, 2000, $plan, '2026-03-16');

    $payment = $this->recorder->claim($transaction, $plan);

    $occurrence = PlannedOccurrence::query()->sole();

    expect($payment->amount)->toBe(2000)
        ->and($occurrence->original_date->toDateString())->toBe('2026-03-15')
        ->and($occurrence->status)->toBe(OccurrenceStatus::Paid)
        ->and($this->recorder->claim($transaction, $plan))->toBeNull()
        ->and(PlannedPayment::query()->count())->toBe(1);
});

it('claims nothing when no occurrence is near the transaction or the payment is refused', function () {
    $plan = linkPlan($this);

    expect($this->recorder->claim(linkTransaction($this, 2000, $plan, '2026-03-30'), $plan))->toBeNull()
        ->and(PlannedPayment::query()->count())->toBe(0);

    $foreign = linkTransaction($this, 2000, $plan, '2026-03-16');
    $foreign->forceFill(['currency' => 'USD'])->save();

    expect($this->recorder->claim($foreign, $plan))->toBeNull();
});

it('skips a closed occurrence and claims the next nearest one', function () {
    $plan = linkPlan($this, ['frequency' => RecurrenceFrequency::EveryWeek, 'match_early_days' => 7, 'match_late_days' => 7]);
    linkOccurrence($plan, '2026-03-15')->update(['status' => OccurrenceStatus::Skipped]);
    $transaction = linkTransaction($this, 2000, $plan, '2026-03-16');

    $this->recorder->claim($transaction, $plan);

    expect(PlannedPayment::query()->sole()->occurrence->original_date->toDateString())->toBe('2026-03-22');
});

it('records a payment when entering a past planned occurrence in the modal', function () {
    $plan = linkPlan($this, ['start_date' => '2026-03-01', 'amount' => 5000]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $plan->id, occurrenceDate: '2026-03-01')
        ->call('save')
        ->assertHasNoErrors();

    $payment = PlannedPayment::query()->sole();

    expect($payment->amount)->toBe(5000)
        ->and($payment->transaction->planned_transaction_id)->toBe($plan->id)
        ->and($payment->occurrence->status)->toBe(OccurrenceStatus::Paid);
});

it('records a partial payment when the entered amount is less than the plan', function () {
    $plan = linkPlan($this, ['start_date' => '2026-03-01', 'amount' => 5000]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $plan->id, occurrenceDate: '2026-03-01')
        ->set('descriptionInput', '20.00 Rent')
        ->call('save')
        ->assertHasNoErrors();

    expect(PlannedPayment::query()->sole()->amount)->toBe(2000)
        ->and(PlannedOccurrence::query()->sole()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('records a payment when a rule links a transaction to a plan', function () {
    $plan = linkPlan($this);
    $transaction = linkTransaction($this, 2000, null, '2026-03-15');

    app(RuleActionExecutor::class)->execute($transaction, [['type' => 'link_to_planned_transaction', 'value' => (string) $plan->id]]);

    expect($transaction->fresh()->planned_transaction_id)->toBe($plan->id)
        ->and(PlannedPayment::query()->sole()->amount)->toBe(2000);
});

it('records no payment when a rule links to another users plan', function () {
    $other = User::factory()->create();
    $theirs = PlannedTransaction::factory()->for($other)->for(Account::factory()->for($other))->create();
    $transaction = linkTransaction($this, 2000, null, '2026-03-15');

    app(RuleActionExecutor::class)->execute($transaction, [['type' => 'link_to_planned_transaction', 'value' => (string) $theirs->id]]);

    expect(PlannedPayment::query()->count())->toBe(0);
});

it('records a payment for each charge when a detected plan is accepted', function () {
    $first = linkTransaction($this, 1000, null, '2026-01-15');
    $second = linkTransaction($this, 1000, null, '2026-02-15');
    $suggestion = AnalysisSuggestion::factory()->create([
        'user_id' => $this->user->id,
        'type' => SuggestionType::RecurringTransaction,
        'payload' => [
            'account_id' => $this->account->id,
            'amount' => 1000,
            'direction' => TransactionDirection::Debit->value,
            'clean_description' => 'Gym',
            'start_date' => '2026-01-15',
            'frequency' => RecurrenceFrequency::EveryMonth->value,
            'matched_transaction_ids' => [$first->id, $second->id],
        ],
    ]);

    $plan = app(SuggestionApplier::class)->applyRecurringTransaction($suggestion, $this->user, null);

    expect(PlannedPayment::query()->count())->toBe(2)
        ->and(PlannedOccurrence::query()->where('planned_transaction_id', $plan->id)->where('status', OccurrenceStatus::Paid->value)->count())->toBe(2);
});

it('takes a payment back when the amount of the transaction shrinks below it and claims again', function () {
    $plan = linkPlan($this, ['amount' => 5000]);
    $occurrence = linkOccurrence($plan);
    $transaction = linkTransaction($this, 5000, $plan);
    $this->recorder->attach($this->user, $transaction->id, $occurrence->id);

    $transaction->update(['amount' => -2000]);

    expect(PlannedPayment::query()->sole()->amount)->toBe(2000)
        ->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('takes the payments back when an edited version changes the plan link or the currency', function () {
    $plan = linkPlan($this);
    $other = linkPlan($this, ['description' => 'Water']);
    $occurrence = linkOccurrence($plan);
    $transaction = linkTransaction($this, 2000, $plan);
    $this->recorder->attach($this->user, $transaction->id, $occurrence->id);

    $child = $transaction->createChild(['planned_transaction_id' => $other->id]);

    expect(PlannedPayment::query()->where('planned_occurrence_id', $occurrence->id)->count())->toBe(0)
        ->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled)
        ->and($child->planned_transaction_id)->toBe($other->id);
});

it('records a payment again when a deleted linked transaction is restored', function () {
    $plan = linkPlan($this);
    $transaction = linkTransaction($this, 2000, $plan);
    $this->recorder->claim($transaction, $plan);
    $transaction->delete();
    expect(PlannedPayment::query()->count())->toBe(0);

    $transaction->restore();

    expect(PlannedPayment::query()->sole()->amount)->toBe(2000);
});

it('takes a payment back when its account is deleted', function () {
    $plan = linkPlan($this);
    $occurrence = linkOccurrence($plan);
    $other = Account::factory()->for($this->user)->create();
    $transaction = Transaction::factory()->for($this->user)->for($other)->create(['amount' => -2000, 'direction' => TransactionDirection::Debit, 'post_date' => '2026-03-15', 'planned_transaction_id' => $plan->id, 'currency' => $this->account->currency]);
    $this->recorder->attach($this->user, $transaction->id, $occurrence->id);

    $other->delete();

    expect(PlannedPayment::query()->count())->toBe(0)->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('unlinks an excluded row through the model so its payment is taken back', function () {
    $plan = linkPlan($this, ['frequency' => RecurrenceFrequency::EveryWeek]);
    $excluded = linkTransaction($this, 2000, $plan, '2026-03-15');
    $excluded->forceFill(['excluded' => true])->save();
    $occurrence = linkOccurrence($plan);
    PlannedPayment::factory()->create(['planned_occurrence_id' => $occurrence->id, 'transaction_id' => $excluded->id, 'amount' => 2000]);
    $counted = linkTransaction($this, 2000, null, '2026-03-15');

    app(App\Services\PlannedTransactionMatcher::class)->matchForUser($this->user);

    expect(PlannedPayment::query()->where('transaction_id', $excluded->id)->count())->toBe(0);
});

it('keeps the payments of a transaction applied by hand without a link when it changes in other ways', function () {
    $plan = linkPlan($this);
    $occurrence = linkOccurrence($plan);
    $transaction = linkTransaction($this, 1000);
    $this->recorder->attach($this->user, $transaction->id, $occurrence->id);

    $transaction->update(['amount' => -1500]);

    expect(PlannedPayment::query()->sole()->amount)->toBe(1000);

    $transaction->update(['amount' => -400]);

    expect(PlannedPayment::query()->count())->toBe(0);
});
