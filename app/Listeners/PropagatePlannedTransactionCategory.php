<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PlannedTransactionCategoryUpdated;
use App\Models\Transaction;

final class PropagatePlannedTransactionCategory
{
    public function handle(PlannedTransactionCategoryUpdated $event): void
    {
        $plannedTransaction = $event->plannedTransaction;

        if ($event->previousCategoryId === $plannedTransaction->category_id) {
            return;
        }

        Transaction::query()
            ->where('planned_transaction_id', $plannedTransaction->id)
            ->update(['category_id' => $plannedTransaction->category_id]);
    }
}
