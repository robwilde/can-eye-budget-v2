<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RedbarkFeedStatus;
use App\Enums\RefreshTrigger;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\RedbarkFeed;
use Illuminate\Console\Command;

final class SyncRedbarkFeedsCommand extends Command
{
    protected $signature = 'app:sync-redbark-feeds';

    protected $description = 'Dispatch a Redbark sync for every healthy feed';

    public function handle(): int
    {
        $index = 0;

        // Feeds needing a new key are skipped: every call would 401 and the user has
        // already been told in the providers panel.
        foreach (RedbarkFeed::query()->where('status', RedbarkFeedStatus::Good)->cursor() as $feed) {
            SyncRedbarkFeedJob::dispatch($feed, RefreshTrigger::Scheduled)
                ->delay(now()->addSeconds($index * 10));

            $index++;
        }

        $this->info("Dispatched Redbark sync jobs for $index feed(s).");

        return self::SUCCESS;
    }
}
