<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Transaction */
final class TransactionResource extends JsonResource
{
    /**
     * Amounts are integer cents, positive, with the direction (debit or credit) saying which way the money moved.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'category_id' => $this->category_id,
            'amount' => $this->amount,
            'direction' => $this->direction->value,
            'currency' => $this->currency,
            'description' => $this->description,
            'clean_description' => $this->clean_description,
            'merchant_name' => $this->merchant_name,
            'post_date' => $this->post_date->toDateString(),
            'transaction_date' => $this->transaction_date?->toDateString(),
            'status' => $this->status->value,
            'kind' => $this->kind->value,
            'source' => $this->source->value,
            'notes' => $this->notes,
            'excluded' => $this->excluded,
            'transfer_pair_id' => $this->transfer_pair_id,
            'suggested_pair_id' => $this->suggested_pair_id,
            'transfer_link_source' => $this->transfer_link_source?->value,
            'planned_transaction_id' => $this->planned_transaction_id,
            'splits' => $this->whenLoaded('splits', fn () => $this->splits->map(fn ($split): array => [
                'id' => $split->id,
                'category_id' => $split->category_id,
                'amount' => $split->amount,
                'notes' => $split->notes,
                'position' => $split->position,
            ])->all()),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
