<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Category */
final class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'group_id' => $this->group_id,
            'budget_tag' => $this->budget_tag?->value,
            'icon' => $this->icon,
            'color' => $this->color,
            'is_hidden' => $this->is_hidden,
        ];
    }
}
