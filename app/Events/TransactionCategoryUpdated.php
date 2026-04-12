<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Transaction;

final class TransactionCategoryUpdated
{
    public function __construct(
        public Transaction $transaction,
        public ?int $previousCategoryId,
    ) {}
}
