<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Enums\OccurrenceStatus;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Jobs\RunTransactionAnalysisJob;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\Account;
use App\Models\PlannedOccurrence;
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\RedbarkAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Recurring\OccurrencePaymentRecorder;
use App\Services\Recurring\PaymentSettlementDeferral;
use App\Services\RedbarkClientFactory;
use App\Services\RedbarkTransactionMatcher;
use App\Services\TransactionIngestor;
use App\Services\Transactions\TransactionDeleter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    Queue::fake([RunTransactionAnalysisJob::class]);
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-08-20 12:00:00', 'UTC'));
    $this->recorder = app(OccurrencePaymentRecorder::class);
});

/** A bill with one stored occurrence, a debit of $cents linked to it and paid against it. */
function batchPaidRow(object $test, Account $account, string $description, int $cents, array $attributes = []): Transaction
{
    $plan = PlannedTransaction::factory()->for($account->user)->for($account)->create([
        'description' => $description, 'amount' => $cents, 'direction' => TransactionDirection::Debit,
        'frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2026-08-10', 'amount_tolerance_bps' => 0,
    ]);
    $occurrence = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-08-10', 'due_date' => '2026-08-10']);
    $transaction = Transaction::factory()->for($account->user)->for($account)->create(array_merge([
        'amount' => -$cents, 'direction' => TransactionDirection::Debit, 'post_date' => '2026-08-10',
        'planned_transaction_id' => $plan->id, 'description' => $description,
    ], $attributes));
    $test->recorder->attach($account->user, $transaction->id, $occurrence->id);

    return $transaction;
}

it('locks every transaction of a Redbark sync batch before any occurrence', function () {
    [$feed, , $account] = linkedRedbarkFeed();
    $rows = [];
    $payload = [];

    foreach ([1, 2, 3] as $number) {
        $rows[] = batchPaidRow($this, $account, "Bill {$number}", 2000, ['redbark_id' => "rb_{$number}", 'source' => TransactionSource::Redbark]);
        $payload[] = [
            'id' => "rb_{$number}", 'accountId' => 'rb_acc_1', 'status' => 'posted', 'date' => '2026-08-10', 'postDate' => '2026-08-10',
            'description' => "BILL {$number}", 'amount' => '-10.00', 'direction' => 'debit', 'merchantName' => "Bill {$number}",
        ];
    }

    fakeRedbark(accounts: [redbarkUpstreamAccount()], transactions: $payload);

    $locks = lockLog(fn () => new SyncRedbarkFeedJob($feed)->handle(app(RedbarkClientFactory::class), app(TransactionIngestor::class), app(RedbarkTransactionMatcher::class)));

    expectBatchLockedFirst($locks, array_map(fn (Transaction $row): int => $row->id, $rows));
    expect(PlannedPayment::query()->whereIn('transaction_id', array_map(fn (Transaction $row): int => $row->id, $rows))->sum('amount'))->toBe(3000);
});

it('locks every expiring hold before any occurrence', function () {
    $account = Account::factory()->create(['import_source' => ImportSource::Redbark]);
    RedbarkAccount::factory()->create(['account_id' => $account->id, 'raw_transactions_payload' => []]);
    $holds = collect([1, 2, 3])->map(fn (int $number): Transaction => batchPaidRow($this, $account, "Hold {$number}", 1500, [
        'source' => TransactionSource::Redbark, 'status' => TransactionStatus::Pending, 'redbark_id' => "rb_hold_{$number}", 'post_date' => '2026-07-25',
    ]));

    $locks = lockLog(fn () => $this->artisan('app:expire-redbark-holds')->assertSuccessful());

    expectTransactionsFirst($locks);
    expectBatchLockedFirst($locks, $holds->pluck('id')->all());
    expect(PlannedPayment::query()->count())->toBe(0)->and(Transaction::query()->whereIn('id', $holds->pluck('id'))->count())->toBe(0);
});

it('locks a deleted parent children and partner before any occurrence', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $mirrorAccount = Account::factory()->for($user)->untracked()->create();

    $parent = batchPaidRow($this, $account, 'Parent', 3000, ['source' => TransactionSource::Manual]);
    $partner = batchPaidRow($this, $mirrorAccount, 'Partner', 3000, ['source' => TransactionSource::Manual, 'transfer_pair_id' => $parent->id]);
    $parent->forceFill(['transfer_pair_id' => $partner->id])->save();
    $fee = batchPaidRow($this, $account, 'Fee', 100, ['source' => TransactionSource::Manual, 'transfer_fee_for_id' => $parent->id]);
    $children = collect(['Child one', 'Child two'])->map(fn (string $name): Transaction => batchPaidRow($this, $account, $name, 500, ['source' => TransactionSource::Manual, 'split_parent_id' => $parent->id]));

    $locks = lockLog(fn () => app(TransactionDeleter::class)->delete(Transaction::query()->whereKey($parent->id)));

    expectTransactionsFirst($locks);
    expectBatchLockedFirst($locks, [$parent->id, $partner->id, $fee->id, ...$children->pluck('id')->all()]);
    expect(PlannedPayment::query()->count())->toBe(0);
});

it('locks the fee of a later deleted transfer leg before the payments of an earlier row', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $mirrorAccount = Account::factory()->for($user)->untracked()->create();

    $plain = batchPaidRow($this, $account, 'Plain', 800, ['source' => TransactionSource::Manual]);
    $leg = batchPaidRow($this, $account, 'Leg', 3000, ['source' => TransactionSource::Manual]);
    $partner = batchPaidRow($this, $mirrorAccount, 'Partner', 3000, ['source' => TransactionSource::Manual, 'transfer_pair_id' => $leg->id]);
    $leg->forceFill(['transfer_pair_id' => $partner->id])->save();
    $fee = batchPaidRow($this, $account, 'Fee', 100, ['source' => TransactionSource::Manual, 'transfer_fee_for_id' => $leg->id]);

    $locks = lockLog(fn () => app(TransactionDeleter::class)->delete(Transaction::query()->whereKey([$plain->id, $leg->id])));

    expectTransactionsFirst($locks);
    expectBatchLockedFirst($locks, [$plain->id, $leg->id, $partner->id, $fee->id]);
});

it('leaves payments unchanged when a batch rolls back', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $row = batchPaidRow($this, $account, 'Rent', 2000);
    $occurrence = PlannedOccurrence::query()->sole();
    expect($occurrence->status)->toBe(OccurrenceStatus::Paid);

    expect(fn () => app(PaymentSettlementDeferral::class)->deferring(function () use ($row): void {
        DB::transaction(function () use ($row): void {
            $row->update(['amount' => -100]);

            expect(PlannedPayment::query()->sole()->amount)->toBe(2000);

            throw new RuntimeException('the batch failed');
        });
    }))->toThrow(RuntimeException::class);

    expect(PlannedPayment::query()->sole()->amount)->toBe(2000)
        ->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Paid);

    Transaction::query()->whereKey($row->id)->update(['amount' => -100]);
    app(PaymentSettlementDeferral::class)->deferring(fn () => null);

    expect(PlannedPayment::query()->sole()->amount)->toBe(2000);
});

it('keeps settling the other rows when one settlement fails', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $first = batchPaidRow($this, $account, 'Rent', 2000);
    $second = batchPaidRow($this, $account, 'Water', 1000);
    $failures = 0;
    Illuminate\Support\Facades\Event::listen('eloquent.updating: '.PlannedOccurrence::class, function () use (&$failures): void {
        if ($failures++ === 0) {
            throw new RuntimeException('a lock wait timed out');
        }
    });
    $reported = [];
    app(Illuminate\Contracts\Debug\ExceptionHandler::class)->reportable(function (RuntimeException $exception) use (&$reported): bool {
        $reported[] = $exception->getMessage();

        return false;
    });

    app(PaymentSettlementDeferral::class)->deferring(function () use ($first, $second): void {
        DB::transaction(function () use ($first, $second): void {
            $first->update(['amount' => -100]);
            $second->update(['amount' => -100]);
        });
    });

    expect($reported)->toBe(['a lock wait timed out'])
        ->and(PlannedPayment::query()->where('transaction_id', $second->id)->sum('amount'))->toBe(100);
});

it('settles a deferred batch once it has committed', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $row = batchPaidRow($this, $account, 'Rent', 2000);
    $occurrence = PlannedOccurrence::query()->sole();

    app(PaymentSettlementDeferral::class)->deferring(function () use ($row, $occurrence): void {
        DB::transaction(fn () => $row->update(['amount' => -100]));

        expect(PlannedPayment::query()->sole()->amount)->toBe(2000)
            ->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Paid);
    });

    expect(PlannedPayment::query()->sole()->amount)->toBe(100)
        ->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('settles a deleted and a restored row of a deferred batch from what was committed', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $deleted = batchPaidRow($this, $account, 'Rent', 2000);
    $flipped = batchPaidRow($this, $account, 'Water', 1000);

    app(PaymentSettlementDeferral::class)->deferring(function () use ($deleted, $flipped): void {
        DB::transaction(function () use ($deleted, $flipped): void {
            $deleted->delete();
            $flipped->delete();
            $flipped->restore();
        });

        expect(PlannedPayment::query()->count())->toBe(2);
    });

    expect(PlannedPayment::query()->where('transaction_id', $deleted->id)->count())->toBe(0)
        ->and(PlannedPayment::query()->where('transaction_id', $flipped->id)->sum('amount'))->toBe(1000);
});

it('takes the payment back immediately outside a batch', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $row = batchPaidRow($this, $account, 'Rent', 2000);

    $row->update(['amount' => -100]);

    expect(PlannedPayment::query()->sole()->amount)->toBe(100);
});
