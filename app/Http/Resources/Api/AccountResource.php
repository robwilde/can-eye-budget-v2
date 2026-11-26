<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Account */
final class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'institution' => $this->institution,
            'type' => $this->type->value,
            'group' => $this->group->value,
            'currency' => $this->currency,
            'balance' => $this->balance,
            'balance_updated_at' => $this->balance_updated_at?->toIso8601String(),
            'credit_limit' => $this->credit_limit,
            'available_funds' => $this->available_funds,
            'status' => $this->status->value,
            'is_tracked' => $this->is_tracked,
            'exclude_from_reports' => $this->exclude_from_reports,
            'last4' => $this->account_last4,
        ];
    }
}
