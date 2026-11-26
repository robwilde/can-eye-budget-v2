<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\BudgetResource;
use App\Models\Budget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class BudgetController extends OwnedReadController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return BudgetResource::collection(
            Budget::query()->where('user_id', $this->owner($request)->id)->orderBy('name')->orderBy('id')->paginate($this->perPage($request))->withQueryString(),
        );
    }

    public function show(Request $request, int $id): BudgetResource
    {
        return new BudgetResource(Budget::query()->where('user_id', $this->owner($request)->id)->findOrFail($id));
    }
}
