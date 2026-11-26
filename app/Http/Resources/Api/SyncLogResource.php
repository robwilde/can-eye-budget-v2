<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\RedbarkSyncLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RedbarkSyncLog */
final class SyncLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'feed_id' => $this->redbark_feed_id,
            'trigger' => $this->trigger->value,
            'status' => $this->status->value,
            'accounts_synced' => $this->accounts_synced,
            'transactions_created' => $this->transactions_created,
            'transactions_updated' => $this->transactions_updated,
            'balances_updated' => $this->balances_updated,
            'since_date' => $this->since_date?->toDateString(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
