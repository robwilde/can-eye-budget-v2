<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\CategorySource;
use App\Events\TransactionCategoryUpdated;
use App\Models\PlannedTransaction;
use App\Models\Transaction;

final class PropagateTransactionCategory
{
    public function handle(TransactionCategoryUpdated $event): void
    {
        // A writer that means "exactly this row" opts out. The bulk apply on
        // the transactions list does: this fan-out is scoped by planned group,
        // not by what the user ticked, so it would rewrite any unselected
        // sibling sharing the plan — ordinary rows the list offered with an
        // enabled checkbox and the user deliberately left alone, and the
        // transfers and splits it renders disabled with a stated reason.
        if (! $event->propagate) {
            return;
        }

        $transaction = $event->transaction;
        $plannedId = $transaction->planned_transaction_id;

        if ($plannedId === null) {
            return;
        }

        if ($event->previousCategoryId === $transaction->category_id) {
            return;
        }

        $newCategoryId = $transaction->category_id;

        // These are mass updates, so they bypass model events and the saving()
        // hook that normally maintains the provenance invariant — category_source
        // has to be written explicitly here or the propagated rows would keep a
        // stale source, or none at all.
        //
        // The event fires from Transaction::updated() for any writer, including
        // the save in RuleActionExecutor::execute(), so siblings inherit the
        // originating row's provenance instead of being asserted Manual — a
        // rule's guess must stay rule-correctable everywhere it lands.
        Transaction::query()
            ->where('planned_transaction_id', $plannedId)
            ->where('id', '!=', $transaction->id)
            ->update([
                'category_id' => $newCategoryId,
                'category_source' => $newCategoryId === null
                    ? null
                    : ($transaction->category_source ?? CategorySource::Manual)->value,
            ]);

        PlannedTransaction::query()
            ->where('id', $plannedId)
            ->update(['category_id' => $newCategoryId]);
    }
}
