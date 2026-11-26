<?php

declare(strict_types=1);

namespace App\Http\Assistant\Tools;

use App\Http\Resources\Api\BudgetResource;
use App\Models\Budget;

final class GetBudgets extends OwnedListTool
{
    public function name(): string
    {
        return 'get_budgets';
    }

    public function description(): string
    {
        return 'Lists the user\'s budgets with amounts in integer cents.';
    }

    protected function model(): string
    {
        return Budget::class;
    }

    protected function resource(): string
    {
        return BudgetResource::class;
    }

    protected function order(): array
    {
        return ['name'];
    }
}
