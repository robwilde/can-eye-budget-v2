<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PlannedTransaction;

final class PlannedTransactionCategoryUpdated
{
    public function __construct(
        public PlannedTransaction $plannedTransaction,
        public ?int $previousCategoryId,
    ) {}
}
