<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\PlannedTransactionResource;
use App\Models\PlannedTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PlannedTransactionController extends OwnedReadController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return PlannedTransactionResource::collection(
            PlannedTransaction::query()->where('user_id', $this->owner($request)->id)->orderBy('start_date')->orderBy('id')->paginate($this->perPage($request))->withQueryString(),
        );
    }

    public function show(Request $request, int $id): PlannedTransactionResource
    {
        return new PlannedTransactionResource(PlannedTransaction::query()->where('user_id', $this->owner($request)->id)->findOrFail($id));
    }
}
