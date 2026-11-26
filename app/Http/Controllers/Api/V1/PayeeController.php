<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\PayeeResource;
use App\Models\Payee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PayeeController extends OwnedReadController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return PayeeResource::collection(
            Payee::query()->where('user_id', $this->owner($request)->id)->orderBy('merchant_name')->orderBy('id')->paginate($this->perPage($request))->withQueryString(),
        );
    }

    public function show(Request $request, int $id): PayeeResource
    {
        return new PayeeResource(Payee::query()->where('user_id', $this->owner($request)->id)->findOrFail($id));
    }
}
