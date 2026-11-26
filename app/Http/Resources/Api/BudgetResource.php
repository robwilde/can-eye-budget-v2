<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\Budget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Budget */
final class BudgetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'budget_plan_id' => $this->budget_plan_id,
            'category_id' => $this->category_id,
            'name' => $this->name,
            'limit_amount' => $this->limit_amount,
            'period' => $this->period->value,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'rollover_enabled' => $this->rollover_enabled,
            'rolled_over_amount' => $this->rolled_over_amount,
        ];
    }
}
