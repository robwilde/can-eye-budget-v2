<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\FeedResource;
use App\Models\RedbarkFeed;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class FeedController extends OwnedReadController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return FeedResource::collection(
            RedbarkFeed::query()->where('user_id', $this->owner($request)->id)->orderBy('id')->paginate($this->perPage($request))->withQueryString(),
        );
    }
}
