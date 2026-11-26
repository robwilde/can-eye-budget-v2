<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\RedbarkFeed;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never carries the API key or the SimpleFIN access URL.
 *
 * @mixin RedbarkFeed
 */
final class FeedResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transport' => $this->transport->value,
            'status' => $this->status->value,
            'auth_failure_count' => $this->auth_failure_count,
            'pending_account_setup' => $this->pending_account_setup,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
        ];
    }
}
