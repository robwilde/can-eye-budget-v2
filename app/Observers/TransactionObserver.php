<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Transaction;
use App\Models\TransactionAttachment;
use App\Services\Recurring\OccurrencePaymentRecorder;
use App\Services\Recurring\PaymentSettlementDeferral;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use WeakMap;

/**
 * Deleting a transaction, soft or forced, deletes its receipts: the rows now and the files once
 * the surrounding transaction commits. Attachments move to the new version on every edit, so
 * deleting the newest version would resurface its parent without them; when a parent becomes the
 * current version again, the receipts go back to it instead of being deleted.
 */
final class TransactionObserver
{
    /** @var WeakMap<Transaction, Collection<int, TransactionAttachment>>|null */
    private static ?WeakMap $beforeForceDelete = null;

    public function updated(Transaction $transaction): void
    {
        if ($transaction->wasChanged(['planned_transaction_id', 'amount', 'currency', 'direction'])) {
            app(PaymentSettlementDeferral::class)->transactionChanged($transaction, $transaction->wasChanged('planned_transaction_id'));
        }
    }

    public function restored(Transaction $transaction): void
    {
        app(PaymentSettlementDeferral::class)->transactionRestored($transaction);
    }

    public function forceDeleting(Transaction $transaction): void
    {
        $this->handBackToResurfacingParent($transaction);

        app(OccurrencePaymentRecorder::class)->release($transaction);

        self::pending()[$transaction] = $this->attachmentsOf($transaction);
    }

    public function deleted(Transaction $transaction): void
    {
        if (! isset(self::pending()[$transaction])) {
            $this->handBackToResurfacingParent($transaction);
        }

        app(PaymentSettlementDeferral::class)->transactionDeleted($transaction);

        $attachments = self::pending()[$transaction] ?? $this->attachmentsOf($transaction);

        unset(self::pending()[$transaction]);

        if ($attachments->isEmpty()) {
            return;
        }

        TransactionAttachment::query()->whereKey($attachments->modelKeys())->delete();

        $transaction->getConnection()->afterCommit(static function () use ($attachments): void {
            foreach ($attachments as $attachment) {
                Storage::disk($attachment->disk)->delete($attachment->path);
            }
        });
    }

    /** @return WeakMap<Transaction, Collection<int, TransactionAttachment>> */
    private static function pending(): WeakMap
    {
        return self::$beforeForceDelete ??= new WeakMap;
    }

    /** @return Collection<int, TransactionAttachment> */
    private function attachmentsOf(Transaction $transaction): Collection
    {
        return TransactionAttachment::query()->where('transaction_id', $transaction->id)->get();
    }

    private function handBackToResurfacingParent(Transaction $transaction): void
    {
        if ($transaction->parent_transaction_id === null) {
            return;
        }

        $resurfaces = Transaction::query()
            ->whereKey($transaction->parent_transaction_id)
            ->whereDoesntHave('children', fn ($query) => $query->whereNull('deleted_at')->whereKeyNot($transaction->id))
            ->exists();

        if ($resurfaces) {
            app(OccurrencePaymentRecorder::class)->carryPayments($transaction->id, $transaction->parent_transaction_id);

            TransactionAttachment::query()
                ->where('transaction_id', $transaction->id)
                ->update(['transaction_id' => $transaction->parent_transaction_id]);
        }
    }
}
