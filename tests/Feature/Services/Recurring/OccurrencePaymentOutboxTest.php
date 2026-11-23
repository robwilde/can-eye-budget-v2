<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Jobs\RunTransactionAnalysisJob;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\Account;
use App\Models\PendingPaymentSettlement;
use App\Models\PlannedOccurrence;
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Recurring\OccurrencePaymentRecorder;
use App\Services\Recurring\PaymentSettlementDeferral;
use App\Services\RedbarkClientFactory;
use App\Services\RedbarkTransactionMatcher;
use App\Services\TransactionIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    Queue::fake([RunTransactionAnalysisJob::class]);
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-08-20 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function outboxPaidRow(object $test, string $description = 'Rent', int $cents = 2000, ?User $user = null): Transaction
{
    $user ??= $test->user;
    $account = $user->is($test->user) ? $test->account : Account::factory()->for($user)->create();
    $plan = PlannedTransaction::factory()->for($user)->for($account)->create([
        'description' => $description, 'amount' => $cents, 'direction' => TransactionDirection::Debit,
        'frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2026-08-10', 'amount_tolerance_bps' => 0,
    ]);
    $occurrence = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-08-10', 'due_date' => '2026-08-10']);
    $transaction = Transaction::factory()->for($user)->for($account)->create([
        'amount' => -$cents, 'direction' => TransactionDirection::Debit, 'post_date' => '2026-08-10',
        'planned_transaction_id' => $plan->id, 'description' => $description,
    ]);
    app(OccurrencePaymentRecorder::class)->attach($user, $transaction->id, $occurrence->id);

    return $transaction;
}

it('settles a committed batch from the outbox when the process died before settling', function () {
    $row = outboxPaidRow($this);
    $occurrence = PlannedOccurrence::query()->sole();

    expect(function () use ($row): void {
        app(PaymentSettlementDeferral::class)->deferring(function () use ($row): void {
            DB::transaction(fn () => $row->update(['amount' => -100]));

            throw new RuntimeException('the process was killed after the commit');
        });
    })->toThrow(RuntimeException::class);

    expect(PendingPaymentSettlement::query()->sole()->transaction_id)->toBe($row->id)
        ->and(PlannedPayment::query()->sole()->amount)->toBe(2000)
        ->and(app(PaymentSettlementDeferral::class)->sweep())->toBe(0)
        ->and(PendingPaymentSettlement::query()->count())->toBe(1);

    $this->travel(10)->minutes();
    $this->artisan('app:settle-pending-payments')->assertSuccessful();

    expect(PendingPaymentSettlement::query()->count())->toBe(0)
        ->and(PlannedPayment::query()->sole()->amount)->toBe(100)
        ->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('leaves no outbox row when a batch rolls back', function () {
    $row = outboxPaidRow($this);

    expect(fn () => app(PaymentSettlementDeferral::class)->deferring(function () use ($row): void {
        DB::transaction(function () use ($row): void {
            $row->update(['amount' => -100]);

            expect(PendingPaymentSettlement::query()->count())->toBe(1);

            throw new RuntimeException('the batch failed');
        });
    }))->toThrow(RuntimeException::class);

    $this->travel(10)->minutes();
    app(PaymentSettlementDeferral::class)->sweep();

    expect(PendingPaymentSettlement::query()->count())->toBe(0)->and(PlannedPayment::query()->sole()->amount)->toBe(2000);
});

it('keeps the outbox row of a failed settlement until a retry succeeds', function () {
    $row = outboxPaidRow($this);
    $failures = 0;
    Event::listen('eloquent.updating: '.PlannedOccurrence::class, function () use (&$failures): void {
        if ($failures++ === 0) {
            throw new RuntimeException('a lock wait timed out');
        }
    });
    $reported = [];
    app(Illuminate\Contracts\Debug\ExceptionHandler::class)->reportable(function (RuntimeException $exception) use (&$reported): bool {
        $reported[] = $exception->getMessage();

        return false;
    });

    app(PaymentSettlementDeferral::class)->deferring(function () use ($row): void {
        DB::transaction(fn () => $row->update(['amount' => -100]));
    });

    expect($reported)->toBe(['a lock wait timed out'])
        ->and(PendingPaymentSettlement::query()->count())->toBe(1)
        ->and(PlannedPayment::query()->sole()->amount)->toBe(2000);

    $this->travel(10)->minutes();

    expect(app(PaymentSettlementDeferral::class)->sweep())->toBe(0)
        ->and(PendingPaymentSettlement::query()->count())->toBe(0)
        ->and(PlannedPayment::query()->sole()->amount)->toBe(100);
});

it('settles older outbox rows of the user when a Redbark sync starts', function () {
    [$feed] = linkedRedbarkFeed(['user_id' => $this->user->id]);
    $mine = outboxPaidRow($this);
    $theirs = outboxPaidRow($this, 'Water', 1000, User::factory()->create());
    Transaction::query()->whereKey([$mine->id, $theirs->id])->update(['amount' => -100]);
    $this->travel(-10)->minutes();
    foreach ([$mine, $theirs] as $stale) {
        PendingPaymentSettlement::query()->create(['transaction_id' => $stale->id, 'link_changed' => false]);
    }
    $this->travel(10)->minutes();
    fakeRedbark(accounts: [redbarkUpstreamAccount()]);

    new SyncRedbarkFeedJob($feed)->handle(app(RedbarkClientFactory::class), app(TransactionIngestor::class), app(RedbarkTransactionMatcher::class));

    expect(PlannedPayment::query()->where('transaction_id', $mine->id)->sum('amount'))->toBe(100)
        ->and(PlannedPayment::query()->where('transaction_id', $theirs->id)->sum('amount'))->toBe(1000)
        ->and(PendingPaymentSettlement::query()->pluck('transaction_id')->all())->toBe([$theirs->id]);
});

it('ors the flags of every change noted for one transaction into a single row', function () {
    $row = outboxPaidRow($this);

    app(PaymentSettlementDeferral::class)->deferring(function () use ($row): void {
        DB::transaction(function () use ($row): void {
            $row->update(['amount' => -100]);
            $first = PendingPaymentSettlement::query()->sole();
            expect($first->restored)->toBeFalse()->and($first->link_changed)->toBeFalse();

            $row->delete();
            $row->restore();

            $merged = PendingPaymentSettlement::query()->sole();
            expect($merged->restored)->toBeTrue()->and($merged->link_changed)->toBeTrue();
        });

        expect(PlannedPayment::query()->sole()->amount)->toBe(2000);
    });

    expect(PendingPaymentSettlement::query()->count())->toBe(0);
});

it('sweeps in ascending transaction id and takes only stale rows', function () {
    $rows = [outboxPaidRow($this, 'A'), outboxPaidRow($this, 'B'), outboxPaidRow($this, 'C')];
    $this->travel(-10)->minutes();
    foreach (array_reverse($rows) as $row) {
        PendingPaymentSettlement::query()->create(['transaction_id' => $row->id, 'link_changed' => true]);
    }
    $this->travel(10)->minutes();
    $fresh = outboxPaidRow($this, 'D');
    PendingPaymentSettlement::query()->create(['transaction_id' => $fresh->id]);

    $locks = lockLog(fn () => app(PaymentSettlementDeferral::class)->sweep());
    $order = collect($locks)->where('table', 'transactions')->flatMap(fn (array $lock): array => $lock['ids'])->unique()->values()->all();

    expect($order)->toBe(array_map(fn (Transaction $row): int => $row->id, $rows))
        ->and(PendingPaymentSettlement::query()->pluck('transaction_id')->all())->toBe([$fresh->id]);
});

it('settles a soft deleted transaction from the outbox and removes the row with a hard deleted one', function () {
    $deleted = outboxPaidRow($this, 'Gone');
    $gone = outboxPaidRow($this, 'Hard');
    $this->travel(-10)->minutes();
    PendingPaymentSettlement::query()->create(['transaction_id' => $deleted->id, 'link_changed' => true]);
    PendingPaymentSettlement::query()->create(['transaction_id' => $gone->id, 'link_changed' => true]);
    $this->travel(10)->minutes();
    Transaction::query()->whereKey($deleted->id)->update(['deleted_at' => now()]);
    PlannedPayment::query()->where('transaction_id', $gone->id)->delete();
    expect(PendingPaymentSettlement::query()->count())->toBe(2);

    Transaction::withTrashed()->whereKey($gone->id)->forceDelete();
    expect(PendingPaymentSettlement::query()->count())->toBe(1);

    app(PaymentSettlementDeferral::class)->sweep();

    expect(PlannedPayment::query()->where('transaction_id', $deleted->id)->count())->toBe(0)
        ->and(PendingPaymentSettlement::query()->count())->toBe(0);
});

it('settles only the rows its own batch noted', function () {
    $mine = outboxPaidRow($this, 'Mine');
    $other = outboxPaidRow($this, 'Other');
    Transaction::query()->whereKey($other->id)->update(['amount' => -100]);

    app(PaymentSettlementDeferral::class)->deferring(function () use ($mine, $other): void {
        DB::transaction(fn () => $mine->update(['amount' => -100]));
        PendingPaymentSettlement::query()->create(['transaction_id' => $other->id, 'link_changed' => false]);
    });

    expect(PlannedPayment::query()->where('transaction_id', $mine->id)->sum('amount'))->toBe(100)
        ->and(PlannedPayment::query()->where('transaction_id', $other->id)->sum('amount'))->toBe(2000)
        ->and(PendingPaymentSettlement::query()->pluck('transaction_id')->all())->toBe([$other->id]);
});

it('starts every settlement with the locking read of its transaction row', function () {
    $row = outboxPaidRow($this);
    $this->travel(-10)->minutes();
    PendingPaymentSettlement::query()->create(['transaction_id' => $row->id, 'link_changed' => false]);
    $this->travel(10)->minutes();
    Transaction::query()->whereKey($row->id)->update(['amount' => -100]);

    $locks = lockLog(fn () => app(PaymentSettlementDeferral::class)->sweep());

    expect($locks[0]['table'])->toBe('transactions')->and($locks[0]['ids'])->toBe([$row->id]);
    expectTransactionsFirst($locks);
});

it('skips a row another settler already took', function () {
    $row = outboxPaidRow($this);
    $this->travel(-10)->minutes();
    PendingPaymentSettlement::query()->create(['transaction_id' => $row->id, 'link_changed' => false]);
    $this->travel(10)->minutes();
    Transaction::query()->whereKey($row->id)->update(['amount' => -100]);

    $settler = app(PaymentSettlementDeferral::class);
    $settler->sweep();
    PendingPaymentSettlement::query()->whereKey($row->id)->delete();

    expect($settler->sweep())->toBe(0)->and(PlannedPayment::query()->sole()->amount)->toBe(100);
});

it('schedules the sweep every fifteen minutes without overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'app:settle-pending-payments'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/15 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
