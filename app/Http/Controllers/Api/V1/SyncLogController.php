<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\SyncLogResource;
use App\Models\RedbarkSyncLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class SyncLogController extends OwnedReadController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return SyncLogResource::collection(
            RedbarkSyncLog::query()->where('user_id', $this->owner($request)->id)->orderByDesc('id')->paginate($this->perPage($request))->withQueryString(),
        );
    }
}
