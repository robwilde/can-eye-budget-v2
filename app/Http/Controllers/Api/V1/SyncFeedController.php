<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\RefreshTrigger;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\RedbarkFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SyncFeedController extends OwnedReadController
{
    /**
     * Queues a sync for each of the token owner's feeds and nobody else's.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $feeds = RedbarkFeed::query()->where('user_id', $this->owner($request)->id)->orderBy('id')->get();

        foreach ($feeds as $feed) {
            SyncRedbarkFeedJob::dispatchFor($feed, RefreshTrigger::Manual);
        }

        return response()->json(['data' => ['queued' => $feeds->count()]], 202);
    }
}
