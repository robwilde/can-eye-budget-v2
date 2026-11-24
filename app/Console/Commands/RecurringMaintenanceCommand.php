<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RecurringMaintenanceJob;
use App\Models\PlannedTransaction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

final class RecurringMaintenanceCommand extends Command
{
    protected $signature = 'app:recurring-maintenance';

    protected $description = 'Queue price-change detection and the stale plan sweep for every user with plans (budget.recurring_maintenance)';

    public function handle(): int
    {
        if (! config('budget.recurring_maintenance')) {
            $this->info('Recurring maintenance is off.');

            return self::SUCCESS;
        }

        $queued = 0;

        User::query()
            ->whereIn('id', PlannedTransaction::query()->visible()->select('user_id'))
            ->chunkById(100, function (Collection $users) use (&$queued): void {
                foreach ($users as $user) {
                    RecurringMaintenanceJob::dispatch($user);
                    $queued++;
                }
            });

        $this->info(sprintf('Queued recurring maintenance for %d user(s).', $queued));

        return self::SUCCESS;
    }
}
