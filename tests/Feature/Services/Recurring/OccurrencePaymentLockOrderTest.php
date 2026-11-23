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
use App\Services\RuleActionExecutor;
use App\Services\SuggestionApplier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-03-20 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->recorder = app(OccurrencePaymentRecorder::class);
});

/**
 * SQLite ignores FOR UPDATE, so the test grammar notes each lock the query builder asks for: the table,
 * the ids it asked for and whether it asked in ascending id order.
 *
 * @return list<array{table: string, ids: list<int>, ascending: bool}>
 */
function lockLog(Closure $run): array
{
    $connection = DB::connection();
    $original = $connection->getQueryGrammar();
    $recorder = new class($connection) extends SQLiteGrammar
    {
        /** @var list<array{table: string, ids: list<int>, ascending: bool}> */
        public array $locks = [];

        protected function compileLock(Builder $query, $value)
        {
            $where = collect($query->wheres)->first(fn (array $where): bool => str_ends_with((string) ($where['column'] ?? ''), '.id') && (isset($where['values']) || isset($where['value'])));
            $ids = $where['values'] ?? (isset($where['value']) ? [$where['value']] : []);

            $this->locks[] = [
                'table' => (string) $query->from,
                'ids' => array_map(intval(...), $ids),
                'ascending' => collect($query->orders)->contains(fn (array $order): bool => $order['column'] === 'id' && $order['direction'] === 'asc'),
            ];

            return '';
        }
    };

    $connection->setQueryGrammar($recorder);

    try {
        $run();
    } finally {
        $connection->setQueryGrammar($original);
    }

    return $recorder->locks;
}

/** A transaction lock may follow an occurrence lock only when the transaction was already locked before it. */
function expectTransactionsFirst(array $locks): void
{
    $heldTransactions = [];
    $heldPlans = [];
    $occurrenceLocked = false;

    foreach ($locks as $lock) {
        if ($lock['table'] === 'planned_occurrences') {
            $occurrenceLocked = true;

            continue;
        }

        if ($lock['table'] === 'planned_transactions') {
            if ($occurrenceLocked) {
                expect(array_diff($lock['ids'], $heldPlans))->toBe([], 'a plan was locked after an occurrence');
            }

            $heldPlans = array_merge($heldPlans, $lock['ids']);

            continue;
        }

        if ($lock['table'] !== 'transactions') {
            continue;
        }

        if ($occurrenceLocked) {
            expect($lock['ids'])->not->toBe([], 'a transaction lock with unknown ids followed an occurrence lock')
                ->and(array_diff($lock['ids'], $heldTransactions))->toBe([], 'a transaction was locked after an occurrence');
        }

        $heldTransactions = array_merge($heldTransactions, $lock['ids']);
    }
}

function lockTables(array $locks): array
{
    return array_map(fn (array $lock): string => $lock['table'], $locks);
}

function lockPlan(object $test, array $attributes = []): PlannedTransaction
{
    return PlannedTransaction::factory()->for($test->user)->for($test->account)->create(array_merge([
        'description' => 'Rent', 'amount' => 2000, 'direction' => TransactionDirection::Debit,
        'frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2026-03-15', 'amount_tolerance_bps' => 0,
    ], $attributes));
}

function lockOccurrence(PlannedTransaction $plan, string $date = '2026-03-15'): PlannedOccurrence
{
    return PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => $date, 'due_date' => $date]);
}

function lockTransaction(object $test, int $cents, ?PlannedTransaction $plan = null, string $date = '2026-03-15'): Transaction
{
    return Transaction::factory()->for($test->user)->for($test->account)->create([
        'amount' => -$cents, 'direction' => TransactionDirection::Debit, 'post_date' => $date, 'planned_transaction_id' => $plan?->id,
    ]);
}

it('locks the transaction before the occurrences on every payment path', function (string $path, bool $locksOccurrences) {
    $plan = lockPlan($this);
    $occurrence = lockOccurrence($plan);
    $transaction = lockTransaction($this, 2000, $plan);
    $paid = lockTransaction($this, 500, $plan, '2026-03-14');
    $payment = $this->recorder->attach($this->user, $paid->id, lockOccurrence($plan, '2026-04-15')->id);
    $other = lockTransaction($this, 2000, null, '2026-03-16');
    $suggested = [lockTransaction($this, 1000, null, '2026-01-15'), lockTransaction($this, 1000, null, '2026-02-15')];
    $gym = lockPlan($this, ['description' => 'Gym', 'amount' => 1000, 'start_date' => '2026-01-15']);

    $locks = lockLog(function () use ($path, $gym, $plan, $occurrence, $transaction, $paid, $payment, $other, $suggested): void {
        match ($path) {
            'attach' => $this->recorder->attach($this->user, $transaction->id, $occurrence->id),
            'claim' => $this->recorder->claim($other, $plan),
            'claimFor' => $this->recorder->claimFor($other, $plan, '2026-03-15'),
            'removePayment' => $this->recorder->removePayment($this->user, $payment->id),
            'revalidate' => $paid->forceFill(['amount' => -100])->save() && $this->recorder->revalidate($paid->fresh(), false),
            'release' => $this->recorder->release($paid),
            'carry' => $this->recorder->carryPayments($paid->id, $transaction->id),
            'claimAll' => $this->recorder->claimAll(new Illuminate\Database\Eloquent\Collection($suggested), $gym),
            'unlink' => $paid->update(['planned_transaction_id' => null]),
            'amount edit' => $paid->update(['amount' => -100]),
            'delete' => $paid->delete(),
            'force delete' => $paid->forceDelete(),
            'new version' => $paid->createChild(['notes' => 'edited']),
            'rule link' => app(RuleActionExecutor::class)->execute($other, [['type' => 'link_to_planned_transaction', 'value' => (string) $plan->id]]),
            'suggestion' => app(SuggestionApplier::class)->applyRecurringTransaction(AnalysisSuggestion::factory()->create([
                'user_id' => $this->user->id,
                'type' => SuggestionType::RecurringTransaction,
                'payload' => [
                    'account_id' => $this->account->id, 'amount' => 1000, 'direction' => 'debit', 'clean_description' => 'Gym',
                    'start_date' => '2026-01-15', 'frequency' => 'every-month', 'matched_transaction_ids' => array_map(fn (Transaction $t): int => $t->id, $suggested),
                ],
            ]), $this->user, null),
            'suggestion with a category' => app(SuggestionApplier::class)->applyRecurringTransaction(AnalysisSuggestion::factory()->create([
                'user_id' => $this->user->id,
                'type' => SuggestionType::RecurringTransaction,
                'payload' => [
                    'account_id' => $this->account->id, 'amount' => 1000, 'direction' => 'debit', 'clean_description' => 'Gym',
                    'start_date' => '2026-01-15', 'frequency' => 'every-month', 'matched_transaction_ids' => array_map(fn (Transaction $t): int => $t->id, $suggested),
                ],
            ]), $this->user, App\Models\Category::factory()->for($this->user)->create()->id),
            'transaction deleter' => app(App\Services\Transactions\TransactionDeleter::class)->delete(Transaction::query()->whereKey([$paid->id])),
            'modal' => Livewire::actingAs($this->user)->test(TransactionModal::class)
                ->dispatch('edit-planned-transaction', id: $plan->id, occurrenceDate: '2026-03-15')->call('save'),
        };
    });

    expect(lockTables($locks))->toContain('transactions');

    if ($locksOccurrences) {
        expect(lockTables($locks))->toContain('planned_occurrences');
    }

    expectTransactionsFirst($locks);
})->with([
    'attach' => ['attach', true],
    'claim' => ['claim', true],
    'claimFor' => ['claimFor', true],
    'removePayment' => ['removePayment', true],
    'revalidate' => ['revalidate', true],
    'release' => ['release', true],
    'carry' => ['carry', false],
    'claimAll' => ['claimAll', true],
    'unlink' => ['unlink', true],
    'amount edit' => ['amount edit', true],
    'delete' => ['delete', true],
    'force delete' => ['force delete', true],
    'new version' => ['new version', false],
    'rule link' => ['rule link', true],
    'suggestion' => ['suggestion', true],
    'modal' => ['modal', true],
]);

it('locks the plan before its occurrences when a claim creates or settles one', function () {
    $plan = lockPlan($this);
    $transaction = lockTransaction($this, 2500, null, '2026-03-16');

    $locks = lockLog(fn () => $this->recorder->claim($transaction, $plan));

    expect(array_values(array_filter(lockTables($locks), fn (string $table): bool => $table !== 'planned_payments')))->toBe(['transactions', 'planned_transactions', 'transactions', 'planned_transactions', 'planned_occurrences'])
        ->and($plan->fresh()->amount_tolerance_bps)->toBe(2500);
    expectTransactionsFirst($locks);
});

it('locks the transaction first when a delete releases payments', function (string $how) {
    $plan = lockPlan($this);
    $transaction = lockTransaction($this, 2000, $plan);
    $this->recorder->attach($this->user, $transaction->id, lockOccurrence($plan)->id);

    $locks = lockLog(fn () => match ($how) {
        'delete' => $transaction->delete(),
        'force delete' => $transaction->forceDelete(),
        'unlink' => $transaction->update(['planned_transaction_id' => null]),
    });

    expect(lockTables($locks)[0])->toBe('transactions')->and(lockTables($locks))->toContain('planned_occurrences');
    expectTransactionsFirst($locks);
})->with(['delete', 'force delete', 'unlink']);

it('locks the transaction first when a restore claims again', function () {
    $plan = lockPlan($this);
    $transaction = lockTransaction($this, 2000, $plan);
    $this->recorder->attach($this->user, $transaction->id, lockOccurrence($plan)->id);
    $transaction->delete();

    $locks = lockLog(fn () => $transaction->restore());

    expect(lockTables($locks)[0])->toBe('transactions')->and(lockTables($locks))->toContain('planned_occurrences');
    expectTransactionsFirst($locks);
});

it('locks the transaction of a payment before its occurrence when removing it', function () {
    $plan = lockPlan($this);
    $transaction = lockTransaction($this, 500, $plan);
    $payment = $this->recorder->attach($this->user, $transaction->id, lockOccurrence($plan)->id);

    $locks = lockLog(fn () => $this->recorder->removePayment($this->user, $payment->id));

    expect(lockTables($locks)[0])->toBe('transactions')->and(lockTables($locks)[1])->toBe('planned_occurrences');
});

it('locks only the occurrence when no transaction is involved', function () {
    $occurrence = lockOccurrence(lockPlan($this));

    $record = lockLog(fn () => $this->recorder->record($this->user, $occurrence->id, 100));
    $paid = lockLog(fn () => $this->recorder->markPaid($this->user, $occurrence->id));
    $manual = PlannedPayment::query()->whereNull('transaction_id')->first();
    $removed = lockLog(fn () => $this->recorder->removePayment($this->user, $manual->id));

    expect(lockTables($record))->toBe(['planned_occurrences'])
        ->and(lockTables($paid))->toBe(['planned_occurrences'])
        ->and(lockTables($removed))->toBe(['planned_occurrences']);
});

it('locks several occurrences in ascending id order', function () {
    $plan = lockPlan($this, ['amount' => 100]);
    $transaction = lockTransaction($this, 300, $plan);
    $first = lockOccurrence($plan, '2026-03-15');
    $second = lockOccurrence($plan, '2026-04-15');
    $third = lockOccurrence($plan, '2026-05-15');

    foreach ([$third, $first, $second] as $occurrence) {
        $this->recorder->attach($this->user, $transaction->id, $occurrence->id, 100);
    }

    $locks = lockLog(fn () => $this->recorder->release($transaction));
    $occurrenceLock = collect($locks)->firstWhere('table', 'planned_occurrences');

    expect($occurrenceLock['ascending'])->toBeTrue()
        ->and($occurrenceLock['ids'])->toHaveCount(3)
        ->and(collect($locks)->firstWhere('table', 'transactions')['ascending'])->toBeTrue();
});

it('locks the transactions of two versions in ascending id order when payments move', function () {
    $plan = lockPlan($this);
    $old = lockTransaction($this, 500, $plan);
    $new = lockTransaction($this, 500, $plan);
    $this->recorder->attach($this->user, $old->id, lockOccurrence($plan)->id);

    $locks = lockLog(fn () => $this->recorder->carryPayments($new->id, $old->id));

    expect($locks[0]['table'])->toBe('transactions')->and($locks[0]['ascending'])->toBeTrue()->and($locks[0]['ids'])->toEqualCanonicalizing([$old->id, $new->id]);
});

it('keeps the payment rules working through the ordered locks', function () {
    $plan = lockPlan($this);
    $occurrence = lockOccurrence($plan);
    $transaction = lockTransaction($this, 2000, $plan);

    $this->recorder->attach($this->user, $transaction->id, $occurrence->id);
    expect($occurrence->fresh()->status)->toBe(OccurrenceStatus::Paid);

    $transaction->delete();

    expect(PlannedPayment::query()->count())->toBe(0)->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled);
});
