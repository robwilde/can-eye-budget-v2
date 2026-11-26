<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\UserRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserRule */
final class RuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'triggers' => $this->triggers,
            'actions' => $this->actions,
            'strict_mode' => $this->strict_mode,
            'is_auto_apply' => $this->is_auto_apply,
            'is_active' => $this->is_active,
            'effective_from' => $this->effective_from?->toDateString(),
            'order' => $this->order,
            'group' => $this->whenLoaded('group', fn () => [
                'id' => $this->group->id,
                'name' => $this->group->name,
                'order' => $this->group->order,
                'is_active' => $this->group->is_active,
                'stop_processing' => $this->group->stop_processing,
            ]),
        ];
    }
}
