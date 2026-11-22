<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncSecurityPricesJob;
use App\Models\Holding;
use App\Models\User;
use App\Services\Investments\SecurityProviderToggle;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

final class SyncSecurityPricesCommand extends Command
{
    protected $signature = 'securities:sync-prices';

    protected $description = 'Queue a price sync job for every user with holdings';

    public function handle(SecurityProviderToggle $providers): int
    {
        if (config('budget.investments.enabled') !== true) {
            $this->info('Investments are disabled (BUDGET_INVESTMENTS_ENABLED).');

            return self::SUCCESS;
        }

        $providers->reconcile();

        $queued = 0;

        User::query()
            ->whereIn('id', Holding::query()->join('accounts', 'accounts.id', '=', 'holdings.account_id')->select('accounts.user_id'))
            ->chunkById(100, function (Collection $users) use (&$queued): void {
                foreach ($users as $user) {
                    SyncSecurityPricesJob::dispatch($user);
                    $queued++;
                }
            });

        $this->info(sprintf('Queued price sync for %d user(s).', $queued));

        return self::SUCCESS;
    }
}
