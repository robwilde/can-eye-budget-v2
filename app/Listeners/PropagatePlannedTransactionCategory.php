<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\CategorySource;
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

        // Mass update: bypasses model events, so the provenance invariant must
        // be written by hand. Planned transactions are user-managed and carry no
        // provenance of their own, so a category coming from one is Manual.
        Transaction::query()
            ->where('planned_transaction_id', $plannedTransaction->id)
            ->update([
                'category_id' => $plannedTransaction->category_id,
                'category_source' => $plannedTransaction->category_id === null
                    ? null
                    : CategorySource::Manual->value,
            ]);
    }
}
