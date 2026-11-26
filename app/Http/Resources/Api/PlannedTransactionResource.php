<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\PlannedTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PlannedTransaction */
final class PlannedTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'transfer_to_account_id' => $this->transfer_to_account_id,
            'category_id' => $this->category_id,
            'amount' => $this->amount,
            'direction' => $this->direction->value,
            'type' => $this->type->value,
            'description' => $this->description,
            'start_date' => $this->start_date->toDateString(),
            'frequency' => $this->frequency->value,
            'recurrence_interval' => $this->recurrence_interval,
            'until_date' => $this->until_date?->toDateString(),
            'status' => $this->status->value,
            'autopay' => $this->autopay,
            'is_pay_cycle_income' => $this->is_pay_cycle_income,
        ];
    }
}
