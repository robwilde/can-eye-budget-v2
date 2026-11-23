<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\PlanAmountStrategy;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Models\Account;
use App\Models\PlannedOccurrence;
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Recurring\OccurrencePaymentRecorder;
use App\Services\Recurring\OccurrenceStateResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->recorder = app(OccurrencePaymentRecorder::class);
});

function payPlan(object $test, array $attributes = [], ?User $user = null): PlannedTransaction
{
    $user ??= $test->user;
    $account = $user->is($test->user) ? $test->account : Account::factory()->for($user)->create();

    return PlannedTransaction::factory()->for($user)->for($account)->create(array_merge([
        'description' => 'Rent',
        'amount' => 200000,
        'direction' => TransactionDirection::Debit,
        'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2026-03-15',
        'amount_tolerance_bps' => 750,
    ], $attributes));
}

function payOccurrence(PlannedTransaction $plan, string $date = '2026-03-15', array $attributes = []): PlannedOccurrence
{
    return PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(array_merge(['original_date' => $date, 'due_date' => $date], $attributes));
}

function payTransaction(object $test, int $cents, array $attributes = [], ?User $user = null): Transaction
{
    $user ??= $test->user;
    $account = $user->is($test->user) ? $test->account : Account::factory()->for($user)->create();

    return Transaction::factory()->for($user)->for($account)->create(array_merge([
        'amount' => -$cents,
        'direction' => TransactionDirection::Debit,
        'description' => 'RENT AGENCY',
        'post_date' => '2026-03-14',
    ], $attributes));
}

it('keeps an occurrence open and partially paid after a partial payment', function () {
    $occurrence = payOccurrence(payPlan($this));
    $transaction = payTransaction($this, 50000);

    $payment = $this->recorder->attach($this->user, $transaction->id, $occurrence->id);

    $fresh = $occurrence->fresh();

    expect($payment->amount)->toBe(50000)
        ->and($fresh->status)->toBe(OccurrenceStatus::Scheduled)
        ->and($fresh->paidCents())->toBe(50000)
        ->and(abs($fresh->effectiveExpectedAmount()) - $fresh->paidCents())->toBe(150000)
        ->and((new OccurrenceStateResolver)->resolve($fresh, $this->user->today())->value)->toBe('partially_paid');
});

it('settles the remainder when marking an occurrence paid', function () {
    $occurrence = payOccurrence(payPlan($this));
    $this->recorder->attach($this->user, payTransaction($this, 50000)->id, $occurrence->id);

    $settled = $this->recorder->markPaid($this->user, $occurrence->id);

    $manual = PlannedPayment::query()->whereNull('transaction_id')->sole();

    expect($manual->amount)->toBe(150000)
        ->and($manual->paid_on->toDateString())->toBe('2026-03-10')
        ->and($settled->status)->toBe(OccurrenceStatus::Paid)
        ->and($settled->closed_manually)->toBeTrue()
        ->and($settled->closed_at)->not->toBeNull();
});

it('refuses to allocate more than a transactions amount', function () {
    $first = payOccurrence(payPlan($this, ['description' => 'Bill A']));
    $second = payOccurrence(payPlan($this, ['description' => 'Bill B', 'start_date' => '2026-03-16']), '2026-03-16');
    $transaction = payTransaction($this, 10000);

    $this->recorder->attach($this->user, $transaction->id, $first->id, 6000);

    expect(fn () => $this->recorder->attach($this->user, $transaction->id, $second->id, 6000))->toThrow(ValidationException::class, "That would allocate more than the transaction's amount.")
        ->and(PlannedPayment::query()->where('planned_occurrence_id', $second->id)->count())->toBe(0)
        ->and($this->recorder->attach($this->user, $transaction->id, $second->id, 4000)->amount)->toBe(4000);
});

it('defaults to what is left of the transaction and refuses once nothing is left', function () {
    $first = payOccurrence(payPlan($this, ['description' => 'Bill A']));
    $second = payOccurrence(payPlan($this, ['description' => 'Bill B', 'start_date' => '2026-03-16']), '2026-03-16');
    $transaction = payTransaction($this, 10000);

    $this->recorder->attach($this->user, $transaction->id, $first->id, 7000);

    expect($this->recorder->attach($this->user, $transaction->id, $second->id)->amount)->toBe(3000)
        ->and(fn () => $this->recorder->attach($this->user, $transaction->id, $second->id))->toThrow(ValidationException::class);
});

it('holds the cap when the same transaction is applied to the same occurrence again', function () {
    $occurrence = payOccurrence(payPlan($this));
    $transaction = payTransaction($this, 100000);

    $this->recorder->attach($this->user, $transaction->id, $occurrence->id, 60000);

    expect(fn () => $this->recorder->attach($this->user, $transaction->id, $occurrence->id, 60000))->toThrow(ValidationException::class)
        ->and($this->recorder->attach($this->user, $transaction->id, $occurrence->id, 40000)->amount)->toBe(100000)
        ->and(PlannedPayment::query()->where('transaction_id', $transaction->id)->count())->toBe(1)
        ->and(PlannedPayment::query()->where('transaction_id', $transaction->id)->sum('amount'))->toBe(100000);
});

it('closes an occurrence on a single payment within tolerance', function () {
    $occurrence = payOccurrence(payPlan($this, ['amount' => 2000]));

    $payment = $this->recorder->attach($this->user, payTransaction($this, 2100)->id, $occurrence->id);

    $fresh = $occurrence->fresh();

    expect($payment->amount)->toBe(2100)
        ->and($fresh->status)->toBe(OccurrenceStatus::Paid)
        ->and($fresh->closed_manually)->toBeFalse()
        ->and($fresh->expected_amount)->toBeNull();
});

it('does not close an occurrence on a single payment outside tolerance that falls short', function () {
    $occurrence = payOccurrence(payPlan($this, ['amount' => 2000]));

    $this->recorder->attach($this->user, payTransaction($this, 1500)->id, $occurrence->id);

    expect($occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('closes an occurrence when several payments reach what it expects within a cent', function () {
    $occurrence = payOccurrence(payPlan($this, ['amount' => 2000, 'amount_tolerance_bps' => 0]));

    $this->recorder->record($this->user, $occurrence->id, 1000);
    expect($occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled);

    $this->recorder->record($this->user, $occurrence->id, 999);

    expect($occurrence->fresh()->status)->toBe(OccurrenceStatus::Paid);
});

it('reopens only an automatically closed occurrence when its payment is removed', function () {
    $auto = payOccurrence(payPlan($this, ['amount' => 2000]));
    $payment = $this->recorder->attach($this->user, payTransaction($this, 2000)->id, $auto->id);
    expect($auto->fresh()->status)->toBe(OccurrenceStatus::Paid);

    $this->recorder->removePayment($this->user, $payment->id);
    expect($auto->fresh()->status)->toBe(OccurrenceStatus::Scheduled)->and($auto->fresh()->closed_at)->toBeNull();

    $manual = payOccurrence(payPlan($this, ['description' => 'Water', 'amount' => 2000, 'start_date' => '2026-03-16']), '2026-03-16');
    $this->recorder->markPaid($this->user, $manual->id);
    $this->recorder->removePayment($this->user, PlannedPayment::query()->where('planned_occurrence_id', $manual->id)->sole()->id);

    expect($manual->fresh()->status)->toBe(OccurrenceStatus::Paid)->and($manual->fresh()->closed_manually)->toBeTrue();
});

it('rejects a payment with an invalid paid on date', function () {
    $occurrence = payOccurrence(payPlan($this));

    expect(fn () => $this->recorder->record($this->user, $occurrence->id, 5000, '2026-02-30'))->toThrow(ValidationException::class, 'That date could not be read.')
        ->and(fn () => $this->recorder->record($this->user, $occurrence->id, 5000, 'soon'))->toThrow(ValidationException::class)
        ->and(PlannedPayment::query()->count())->toBe(0);
});

it('records a payment without a transaction on a past date and refuses a future one', function () {
    $occurrence = payOccurrence(payPlan($this));

    $payment = $this->recorder->record($this->user, $occurrence->id, 5000, '2026-03-01');

    expect($payment->transaction_id)->toBeNull()
        ->and($payment->paid_on->toDateString())->toBe('2026-03-01')
        ->and(fn () => $this->recorder->record($this->user, $occurrence->id, 5000, '2026-03-11'))->toThrow(ValidationException::class)
        ->and(fn () => $this->recorder->record($this->user, $occurrence->id, 0))->toThrow(ValidationException::class)
        ->and($this->recorder->record($this->user, $occurrence->id, 100)->paid_on->toDateString())->toBe('2026-03-10');
});

it('refuses to attach another users transaction', function () {
    $other = User::factory()->create();
    $occurrence = payOccurrence(payPlan($this));
    $theirs = payTransaction($this, 50000, user: $other);

    expect(fn () => $this->recorder->attach($this->user, $theirs->id, $occurrence->id))->toThrow(ModelNotFoundException::class)
        ->and(PlannedPayment::query()->count())->toBe(0);
});

it('refuses to pay another users occurrence', function () {
    $other = User::factory()->create();
    $theirs = payOccurrence(payPlan($this, user: $other));
    $mine = payTransaction($this, 50000);

    expect(fn () => $this->recorder->attach($this->user, $mine->id, $theirs->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->recorder->record($this->user, $theirs->id, 100))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->recorder->markPaid($this->user, $theirs->id))->toThrow(ModelNotFoundException::class)
        ->and(PlannedPayment::query()->count())->toBe(0);
});

it('refuses to remove another users payment', function () {
    $other = User::factory()->create();
    $theirs = payOccurrence(payPlan($this, user: $other));
    $payment = PlannedPayment::factory()->create(['planned_occurrence_id' => $theirs->id, 'amount' => 100]);

    expect(fn () => $this->recorder->removePayment($this->user, $payment->id))->toThrow(ModelNotFoundException::class)
        ->and($payment->fresh())->not->toBeNull();
});

it('only takes payments on an open occurrence', function () {
    $transaction = payTransaction($this, 5000);

    foreach ([OccurrenceStatus::Skipped, OccurrenceStatus::Missed, OccurrenceStatus::Paid] as $index => $status) {
        $occurrence = payOccurrence(payPlan($this, ['description' => "Bill {$index}", 'start_date' => '2026-03-1'.$index]), '2026-03-1'.$index, ['status' => $status]);

        expect(fn () => $this->recorder->attach($this->user, $transaction->id, $occurrence->id, 100))->toThrow(ValidationException::class, 'Only an open occurrence can take a payment.');
    }

    expect(PlannedPayment::query()->count())->toBe(0);
});

it('refuses a transaction in another currency than the plan account', function () {
    $occurrence = payOccurrence(payPlan($this));
    $foreign = payTransaction($this, 5000);
    $foreign->forceFill(['currency' => 'USD'])->save();

    expect(fn () => $this->recorder->attach($this->user, $foreign->id, $occurrence->id))->toThrow(ValidationException::class, 'another currency');
});

it('refuses a transaction that is no longer the current version', function () {
    $occurrence = payOccurrence(payPlan($this));
    $old = payTransaction($this, 5000);
    $old->createChild(['description' => 'edited']);

    expect(fn () => $this->recorder->attach($this->user, $old->id, $occurrence->id))->toThrow(ModelNotFoundException::class);
});

it('writes nothing when a payment is refused part way', function () {
    $occurrence = payOccurrence(payPlan($this));
    $transaction = payTransaction($this, 1000);

    try {
        $this->recorder->attach($this->user, $transaction->id, $occurrence->id, 5000);
    } catch (ValidationException) {
    }

    expect(PlannedPayment::query()->count())->toBe(0)->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('learns a new description and merchant key only from a payment that settles', function () {
    $plan = payPlan($this, ['amount' => 2000, 'merchant_key' => 'rent-co']);
    $partial = payOccurrence($plan, '2026-03-15');
    $transaction = payTransaction($this, 500, ['description' => 'RENT AGENCY PTY']);
    $key = $transaction->merchant_key;

    $this->recorder->attach($this->user, $transaction->id, $partial->id);

    expect($plan->fresh()->learned_aliases)->toBeNull();

    $settle = payTransaction($this, 2000, ['description' => 'RENT AGENCY PTY']);
    $other = payOccurrence($plan, '2026-04-15');
    $this->recorder->attach($this->user, $settle->id, $other->id);

    expect($plan->fresh()->learned_aliases)->toBe(['descriptions' => ['RENT AGENCY PTY'], 'merchant_keys' => [$key]]);

    $again = payOccurrence($plan, '2026-05-15');
    $this->recorder->attach($this->user, payTransaction($this, 2000, ['description' => 'rent agency pty'])->id, $again->id);

    expect($plan->fresh()->learned_aliases)->toBe(['descriptions' => ['RENT AGENCY PTY'], 'merchant_keys' => [$key]]);
});

it('does not learn the plans own description or merchant key', function () {
    $transaction = payTransaction($this, 2000, ['description' => 'rent']);
    $plan = payPlan($this, ['amount' => 2000, 'merchant_key' => $transaction->merchant_key]);

    $this->recorder->attach($this->user, $transaction->id, payOccurrence($plan)->id);

    expect($plan->fresh()->learned_aliases)->toBeNull();
});

it('widens the tolerance when a single settling payment lands outside it, up to a limit', function () {
    $plan = payPlan($this, ['amount' => 10000, 'amount_tolerance_bps' => 500]);

    $this->recorder->attach($this->user, payTransaction($this, 11200)->id, payOccurrence($plan)->id);
    expect($plan->fresh()->amount_tolerance_bps)->toBe(1200);

    $this->recorder->attach($this->user, payTransaction($this, 11000)->id, payOccurrence($plan, '2026-04-15')->id);
    expect($plan->fresh()->amount_tolerance_bps)->toBe(1200);

    $this->recorder->attach($this->user, payTransaction($this, 20000)->id, payOccurrence($plan, '2026-05-15')->id);
    expect($plan->fresh()->amount_tolerance_bps)->toBe(2500);
});

it('does not widen the tolerance for a partial or a within tolerance payment or several payments', function () {
    $plan = payPlan($this, ['amount' => 10000, 'amount_tolerance_bps' => 500]);

    $this->recorder->attach($this->user, payTransaction($this, 3000)->id, payOccurrence($plan)->id);
    $this->recorder->attach($this->user, payTransaction($this, 10300)->id, payOccurrence($plan, '2026-04-15')->id);
    $split = payOccurrence($plan, '2026-05-15');
    $this->recorder->record($this->user, $split->id, 6000);
    $this->recorder->attach($this->user, payTransaction($this, 9000)->id, $split->id);

    expect($plan->fresh()->amount_tolerance_bps)->toBe(500);
});

it('splits one transaction across several occurrences and closes each that is covered', function () {
    $plan = payPlan($this, ['amount' => 2000]);
    $march = payOccurrence($plan, '2026-03-15');
    $april = payOccurrence($plan, '2026-04-15');
    $transaction = payTransaction($this, 4000);

    $this->recorder->attach($this->user, $transaction->id, $march->id, 2000);
    $this->recorder->attach($this->user, $transaction->id, $april->id);

    expect($march->fresh()->status)->toBe(OccurrenceStatus::Paid)
        ->and($april->fresh()->status)->toBe(OccurrenceStatus::Paid)
        ->and(PlannedPayment::query()->where('transaction_id', $transaction->id)->count())->toBe(2);
});

it('lets one occurrence be paid by several transactions', function () {
    $occurrence = payOccurrence(payPlan($this, ['amount' => 2000, 'amount_tolerance_bps' => 0]));

    $this->recorder->attach($this->user, payTransaction($this, 700)->id, $occurrence->id);
    $this->recorder->attach($this->user, payTransaction($this, 1300)->id, $occurrence->id);

    expect($occurrence->fresh()->status)->toBe(OccurrenceStatus::Paid)->and($occurrence->fresh()->paidCents())->toBe(2000);
});

it('allows an over payment', function () {
    $occurrence = payOccurrence(payPlan($this, ['amount' => 2000]));

    $this->recorder->attach($this->user, payTransaction($this, 5000)->id, $occurrence->id);

    expect($occurrence->fresh()->status)->toBe(OccurrenceStatus::Paid)->and($occurrence->fresh()->paidCents())->toBe(5000);
});

it('marks an occurrence paid with nothing to add when it is already covered', function () {
    $occurrence = payOccurrence(payPlan($this, ['amount' => 2000]), attributes: ['expected_amount' => 2000]);
    PlannedPayment::factory()->create(['planned_occurrence_id' => $occurrence->id, 'amount' => 2000]);

    $this->recorder->markPaid($this->user, $occurrence->id);

    expect(PlannedPayment::query()->count())->toBe(1)->and($occurrence->fresh()->closed_manually)->toBeTrue();
});

it('uses the amount of its own an occurrence expects, not the plans', function () {
    $occurrence = payOccurrence(payPlan($this, ['amount' => 2000]), attributes: ['expected_amount' => 3000]);

    $this->recorder->attach($this->user, payTransaction($this, 2000)->id, $occurrence->id);

    expect($occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled);

    $this->recorder->attach($this->user, payTransaction($this, 1000)->id, $occurrence->id);

    expect($occurrence->fresh()->status)->toBe(OccurrenceStatus::Paid);
});

it('expects the last paid amount for a last paid plan and the average for an average plan', function () {
    $last = payPlan($this, ['amount' => 2000, 'amount_strategy' => PlanAmountStrategy::LastPaid]);
    $avg = payPlan($this, ['description' => 'Power', 'amount' => 2000, 'amount_strategy' => PlanAmountStrategy::Average, 'start_date' => '2026-03-16']);

    foreach ([['2026-01-15', 3000], ['2026-02-15', 5000]] as [$date, $cents]) {
        $paid = payOccurrence($last, $date, ['status' => OccurrenceStatus::Paid]);
        PlannedPayment::factory()->create(['planned_occurrence_id' => $paid->id, 'amount' => $cents]);
        $paid = payOccurrence($avg, $date, ['status' => OccurrenceStatus::Paid]);
        PlannedPayment::factory()->create(['planned_occurrence_id' => $paid->id, 'amount' => $cents]);
    }

    $lastOpen = payOccurrence($last, '2026-03-15');
    $avgOpen = payOccurrence($avg, '2026-03-16');

    $this->recorder->attach($this->user, payTransaction($this, 5000)->id, $lastOpen->id);
    $this->recorder->attach($this->user, payTransaction($this, 4000)->id, $avgOpen->id);

    expect($lastOpen->fresh()->status)->toBe(OccurrenceStatus::Paid)
        ->and($avgOpen->fresh()->status)->toBe(OccurrenceStatus::Paid);

    $this->recorder->attach($this->user, payTransaction($this, 4500)->id, payOccurrence($last, '2026-04-15')->id);

    expect(PlannedOccurrence::query()->whereDate('original_date', '2026-04-15')->sole()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('reconciles an occurrence after its expected amount changes', function () {
    $occurrence = payOccurrence(payPlan($this, ['amount' => 2000, 'amount_tolerance_bps' => 0]));
    $this->recorder->attach($this->user, payTransaction($this, 1200)->id, $occurrence->id);

    app(App\Services\Recurring\OccurrenceActions::class)->setExpectedAmount($this->user, $occurrence->id, 1200);

    expect($occurrence->fresh()->status)->toBe(OccurrenceStatus::Paid);

});

it('refuses to skip or miss an occurrence that has money against it', function () {
    $occurrence = payOccurrence(payPlan($this));
    $this->recorder->record($this->user, $occurrence->id, 100);
    $actions = app(App\Services\Recurring\OccurrenceActions::class);

    expect(fn () => $actions->skip($this->user, $occurrence->id))->toThrow(ValidationException::class)
        ->and(fn () => $actions->markMissed($this->user, $occurrence->id))->toThrow(ValidationException::class)
        ->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled);

    $this->recorder->removePayment($this->user, PlannedPayment::query()->sole()->id);

    expect($actions->skip($this->user, $occurrence->id)->status)->toBe(OccurrenceStatus::Skipped);
});

it('refuses a transaction in the other direction than the bill', function () {
    $occurrence = payOccurrence(payPlan($this));
    $refund = payTransaction($this, 5000, ['amount' => 5000, 'direction' => TransactionDirection::Credit]);

    expect(fn () => $this->recorder->attach($this->user, $refund->id, $occurrence->id))->toThrow(ValidationException::class, 'other direction')
        ->and(PlannedPayment::query()->count())->toBe(0);
});

it('reopens a history following occurrence when a payment is removed, whatever it paid before', function () {
    $plan = payPlan($this, ['amount' => 10000, 'amount_strategy' => PlanAmountStrategy::LastPaid, 'amount_tolerance_bps' => 0]);
    $occurrence = payOccurrence($plan, '2026-03-15', ['expected_amount' => 10000]);
    $this->recorder->attach($this->user, payTransaction($this, 6000)->id, $occurrence->id);
    $second = $this->recorder->attach($this->user, payTransaction($this, 4000)->id, $occurrence->id);
    expect($occurrence->fresh()->status)->toBe(OccurrenceStatus::Paid);

    $this->recorder->removePayment($this->user, $second->id);

    expect($occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('widens the tolerance of a last paid plan from a single over payment', function () {
    $plan = payPlan($this, ['amount' => 10000, 'amount_strategy' => PlanAmountStrategy::LastPaid, 'amount_tolerance_bps' => 500]);
    $history = payOccurrence($plan, '2026-02-15', ['status' => OccurrenceStatus::Paid]);
    PlannedPayment::factory()->create(['planned_occurrence_id' => $history->id, 'amount' => 10000]);

    $this->recorder->attach($this->user, payTransaction($this, 11500)->id, payOccurrence($plan)->id);

    expect($plan->fresh()->amount_tolerance_bps)->toBe(1500);
});

it('expects the average of past payments, not the declared amount', function () {
    $plan = payPlan($this, ['amount' => 2000, 'amount_strategy' => PlanAmountStrategy::Average, 'amount_tolerance_bps' => 0]);

    foreach ([['2026-01-15', 6000], ['2026-02-15', 8000]] as [$date, $cents]) {
        PlannedPayment::factory()->create(['planned_occurrence_id' => payOccurrence($plan, $date, ['status' => OccurrenceStatus::Paid])->id, 'amount' => $cents]);
    }

    $open = payOccurrence($plan);
    $this->recorder->attach($this->user, payTransaction($this, 2000)->id, $open->id);

    expect($open->fresh()->status)->toBe(OccurrenceStatus::Scheduled);

    $this->recorder->attach($this->user, payTransaction($this, 5000)->id, $open->id);

    expect($open->fresh()->status)->toBe(OccurrenceStatus::Paid);
});

it('pays the occurrence the user opened, including one snoozed past the match window', function () {
    $plan = payPlan($this);
    $occurrence = payOccurrence($plan, '2026-03-01', ['snoozed_until' => '2026-03-25']);
    $other = payOccurrence($plan, '2026-03-15');
    $transaction = payTransaction($this, 100000, ['post_date' => '2026-03-09']);

    $payment = $this->recorder->claimFor($transaction, $plan, '2026-03-25');

    expect($payment->planned_occurrence_id)->toBe($occurrence->id)
        ->and(PlannedPayment::query()->where('planned_occurrence_id', $other->id)->count())->toBe(0);
});

it('creates and pays an occurrence that was never stored and refuses a closed one', function () {
    $plan = payPlan($this, ['amount' => 5000]);
    $transaction = payTransaction($this, 5000, ['post_date' => '2026-03-15']);

    $payment = $this->recorder->claimFor($transaction, $plan, '2026-03-15');

    expect($payment->occurrence->original_date->toDateString())->toBe('2026-03-15')
        ->and($payment->occurrence->status)->toBe(OccurrenceStatus::Paid)
        ->and($this->recorder->claimFor(payTransaction($this, 5000), $plan, '2026-03-15'))->toBeNull();
});
