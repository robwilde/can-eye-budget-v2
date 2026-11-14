<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateInsightsJob;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

final class GenerateInsightsCommand extends Command
{
    protected $signature = 'insights:generate';

    protected $description = 'Dispatch an insight generation run for every user';

    public function handle(): int
    {
        if (config('budget.insights_enabled') !== true) {
            $this->info('Insights are disabled (BUDGET_INSIGHTS_ENABLED).');

            return self::SUCCESS;
        }

        $dispatched = 0;

        User::query()->chunkById(100, function (Collection $users) use (&$dispatched): void {
            foreach ($users as $user) {
                GenerateInsightsJob::dispatch($user);
                $dispatched++;
            }
        });

        $this->info("Dispatched insight runs for {$dispatched} user(s).");

        return self::SUCCESS;
    }
}
