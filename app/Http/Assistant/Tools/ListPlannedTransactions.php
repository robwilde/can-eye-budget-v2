<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Http\Resources\Api\PlannedTransactionResource;
use App\Models\PlannedTransaction;

final class ListPlannedTransactions extends OwnedListTool
{
    public function name(): string
    {
        return 'list_planned_transactions';
    }

    public function description(): string
    {
        return 'Lists the user\'s planned (recurring or future) transactions, amounts in integer cents.';
    }

    protected function model(): string
    {
        return PlannedTransaction::class;
    }

    protected function resource(): string
    {
        return PlannedTransactionResource::class;
    }

    protected function order(): array
    {
        return ['start_date'];
    }
}
