<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TransactionCategoryUpdated;
use App\Models\PlannedTransaction;
use App\Models\Transaction;

final class PropagateTransactionCategory
{
    public function handle(TransactionCategoryUpdated $event): void
    {
        $transaction = $event->transaction;
        $plannedId = $transaction->planned_transaction_id;

        if ($plannedId === null) {
            return;
        }

        if ($event->previousCategoryId === $transaction->category_id) {
            return;
        }

        $newCategoryId = $transaction->category_id;

        Transaction::query()
            ->where('planned_transaction_id', $plannedId)
            ->where('id', '!=', $transaction->id)
            ->update(['category_id' => $newCategoryId]);

        PlannedTransaction::query()
            ->where('id', $plannedId)
            ->update(['category_id' => $newCategoryId]);
    }
}
