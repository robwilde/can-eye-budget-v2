<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\AccountResource;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AccountController extends OwnedReadController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return AccountResource::collection(
            Account::query()->where('user_id', $this->owner($request)->id)->orderBy('name')->orderBy('id')->paginate($this->perPage($request))->withQueryString(),
        );
    }

    public function show(Request $request, int $id): AccountResource
    {
        return new AccountResource(Account::query()->where('user_id', $this->owner($request)->id)->findOrFail($id));
    }
}
