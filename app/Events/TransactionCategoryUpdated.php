<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Transaction;

final class TransactionCategoryUpdated
{
    /**
     * @param  bool  $propagate  Whether this change should fan out to the
     *                           planned group. False for a bulk apply, whose
     *                           contract is "exactly the rows I ticked": the
     *                           list renders transfers and splits with a
     *                           disabled checkbox and a reason, so rewriting
     *                           one through its planned_transaction_id would
     *                           contradict what the page just told the user.
     */
    public function __construct(
        public Transaction $transaction,
        public ?int $previousCategoryId,
        public bool $propagate = true,
    ) {}
}
