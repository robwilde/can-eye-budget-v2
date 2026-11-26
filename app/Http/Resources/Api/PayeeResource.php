<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\Payee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payee */
final class PayeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_key' => $this->merchant_key,
            'merchant_name' => $this->merchant_name,
            'status' => $this->status->value,
            'suggested_category_id' => $this->suggested_category_id,
            'confirmed_category_id' => $this->confirmed_category_id,
            'confidence' => $this->confidence,
            'user_rule_id' => $this->user_rule_id,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
        ];
    }
}
