<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\MaintainGoalsJob;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

final class MaintainGoalsCommand extends Command
{
    protected $signature = 'goals:maintain';

    protected $description = 'Queue the nightly goal upkeep job for every user with goals';

    public function handle(): int
    {
        if (config('budget.goals_enabled') !== true) {
            $this->info('Goals are disabled (BUDGET_GOALS_ENABLED).');

            return self::SUCCESS;
        }

        $queued = 0;

        User::query()
            ->whereIn('id', Goal::query()->select('user_id'))
            ->chunkById(100, function (Collection $users) use (&$queued): void {
                foreach ($users as $user) {
                    MaintainGoalsJob::dispatch($user);
                    $queued++;
                }
            });

        $this->info(sprintf('Queued goal upkeep for %d user(s).', $queued));

        return self::SUCCESS;
    }
}
