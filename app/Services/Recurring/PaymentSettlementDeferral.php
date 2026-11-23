<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\Models\PendingPaymentSettlement;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Settles occurrence payments after a transaction changed, deleted or restored: at once, or, inside a batch
 * that touches rows nobody can list up front (a bank sync adopting, matching and creating rows), once the
 * batch's database transaction has committed. The batch only writes an outbox row per transaction
 * (pending_payment_settlements), inside its own database transaction: a rollback discards the rows, a batch
 * that locks no occurrence keeps the lock order (transactions, plans, occurrences), and a process that dies
 * after the commit leaves the rows for sweep() to settle. Each row is settled in its own ordered transaction,
 * starting with the locking read of the transaction row, and deleted in that same transaction (the outbox row
 * comes last); a failure is reported and leaves the row for the next sweep.
 */
final class PaymentSettlementDeferral
{
    public const int STALE_MINUTES = 5;

    private const int SWEEP_LIMIT = 1000;

    private int $depth = 0;

    /** @var array<int, true> */
    private array $noted = [];

    public function __construct(private readonly OccurrencePaymentRecorder $recorder) {}

    /**
     * Runs a batch, then settles what it noted. Wrap the batch's whole database transaction, not part of it.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $batch
     * @return TResult
     *
     * @throws Throwable
     */
    public function deferring(Closure $batch): mixed
    {
        $this->depth++;

        try {
            $result = $batch();
        } finally {
            $this->depth--;
        }

        if ($this->depth === 0) {
            $noted = array_keys($this->noted);
            $this->noted = [];

            if ($noted !== []) {
                $this->settleRows(PendingPaymentSettlement::query()->whereIn('transaction_id', $noted), null);
            }
        }

        return $result;
    }

    /**
     * Settles every outbox row last touched before the stale line, in ascending transaction id: what a batch
     * that died after its commit, or a settlement that failed, left behind. With $userId only that user's.
     *
     * @return int how many rows are left afterwards among those it tried
     */
    public function sweep(?int $userId = null, int $olderThanMinutes = self::STALE_MINUTES): int
    {
        $rows = PendingPaymentSettlement::query()
            ->where('updated_at', '<=', CarbonImmutable::now()->subMinutes($olderThanMinutes))
            ->when($userId !== null, fn ($query) => $query->whereIn('transaction_id', Transaction::withTrashed()->where('user_id', $userId)->select('id')));

        return $this->settleRows($rows, self::SWEEP_LIMIT);
    }

    public function transactionChanged(Transaction $transaction, bool $linkChanged): void
    {
        if ($this->depth === 0) {
            $this->recorder->revalidate($transaction, $linkChanged);

            return;
        }

        $this->note($transaction, restored: false, linkChanged: $linkChanged);
    }

    public function transactionRestored(Transaction $transaction): void
    {
        if ($this->depth === 0) {
            $this->claimLinked($transaction);

            return;
        }

        $this->note($transaction, restored: true, linkChanged: false);
    }

    public function transactionDeleted(Transaction $transaction): void
    {
        if ($this->depth === 0) {
            $this->recorder->release($transaction);

            return;
        }

        $this->note($transaction, restored: false, linkChanged: true);
    }

    private function note(Transaction $transaction, bool $restored, bool $linkChanged): void
    {
        $now = CarbonImmutable::now();
        $this->noted[$transaction->id] = true;

        PendingPaymentSettlement::query()->insertOrIgnore([
            'transaction_id' => $transaction->id,
            'restored' => false,
            'link_changed' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $row = PendingPaymentSettlement::query()->where('transaction_id', $transaction->id);
        $flags = array_filter(['restored' => $restored, 'link_changed' => $linkChanged]);

        $row->update([...$flags, 'updated_at' => $now]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<PendingPaymentSettlement>  $rows
     */
    private function settleRows(\Illuminate\Database\Eloquent\Builder $rows, ?int $limit): int
    {
        $left = 0;

        foreach ($rows->orderBy('transaction_id')->when($limit !== null, fn ($query) => $query->limit($limit))->pluck('transaction_id') as $id) {
            try {
                DB::transaction(fn () => $this->settleOne((int) $id));
            } catch (Throwable $exception) {
                $left++;
                report($exception);
            }
        }

        return $left;
    }

    /**
     * Settles one transaction in one transaction of its own. The first statement is the locking read of the
     * transaction row (the order's first lock, and the read that fixes what everything after it sees), then
     * the outbox row is read again for its current flags and deleted by key once settled; a row another
     * settler already took is skipped.
     */
    private function settleOne(int $id): void
    {
        $transaction = Transaction::withTrashed()->whereKey($id)->lockForUpdate()->first();
        $row = PendingPaymentSettlement::query()->whereKey($id)->first();

        if (! $row instanceof PendingPaymentSettlement) {
            return;
        }

        if ($transaction instanceof Transaction) {
            if ($transaction->trashed()) {
                $this->recorder->release($transaction);
            } else {
                $this->recorder->revalidate($transaction, $row->link_changed);

                if ($row->restored) {
                    $this->claimLinked($transaction);
                }
            }
        }

        PendingPaymentSettlement::query()->whereKey($id)->delete();
    }

    private function claimLinked(Transaction $transaction): void
    {
        if ($transaction->planned_transaction_id === null) {
            return;
        }

        $plan = PlannedTransaction::query()->where('user_id', $transaction->user_id)->find($transaction->planned_transaction_id);

        if ($plan instanceof PlannedTransaction) {
            $this->recorder->claim($transaction, $plan);
        }
    }
}
