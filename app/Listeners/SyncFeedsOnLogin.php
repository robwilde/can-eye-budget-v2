<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\RedbarkFeedStatus;
use App\Enums\RefreshTrigger;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\RedbarkFeed;
use App\Models\RedbarkSyncLog;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * For a user who turned on "sync when I log in", queues a sync of each healthy feed, at most one
 * per feed per day however often they log in.
 */
final class SyncFeedsOnLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || ! $user->preferences->autoSyncOnLogin) {
            return;
        }

        $feeds = RedbarkFeed::query()
            ->where('user_id', $user->id)
            ->where('status', RedbarkFeedStatus::Good)
            ->get();

        foreach ($feeds as $feed) {
            $alreadyToday = RedbarkSyncLog::query()
                ->where('redbark_feed_id', $feed->id)
                ->where('trigger', RefreshTrigger::Login)
                ->where('created_at', '>=', now()->startOfDay())
                ->exists();

            if (! $alreadyToday) {
                SyncRedbarkFeedJob::dispatchFor($feed, RefreshTrigger::Login);
            }
        }
    }
}
