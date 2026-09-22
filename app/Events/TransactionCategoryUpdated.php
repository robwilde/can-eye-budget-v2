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
     *                           fan-out is scoped by planned_transaction_id,
     *                           not by what the user selected, so it rewrites
     *                           ANY unticked sibling sharing the plan. That
     *                           includes ordinary rows the list offered with an
     *                           enabled checkbox the user chose to leave alone,
     *                           and — most visibly — the transfers and splits
     *                           eligibleForBulk() renders disabled with a
     *                           stated reason, which the page has just told the
     *                           user are not categorised here.
     */
    public function __construct(
        public Transaction $transaction,
        public ?int $previousCategoryId,
        public bool $propagate = true,
    ) {}
}
