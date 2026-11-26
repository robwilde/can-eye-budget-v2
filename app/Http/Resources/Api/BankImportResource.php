<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\BankImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BankImport */
final class BankImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'preset' => $this->preset->value,
            'status' => $this->status->value,
            'row_count' => $this->row_count,
            'imported_count' => $this->imported_count,
            'skipped_count' => $this->skipped_count,
            'restored_count' => $this->restored_count,
            'error_summary' => $this->error_summary,
            'row_errors' => $this->row_errors,
            'unresolved_categories' => $this->unresolved_categories,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
