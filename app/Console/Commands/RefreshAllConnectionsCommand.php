<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use App\Jobs\RefreshBasiqConnectionsJob;
use App\Models\BasiqRefreshLog;
use App\Models\User;
use Illuminate\Console\Command;

final class RefreshAllConnectionsCommand extends Command
{
    protected $signature = 'app:refresh-all-connections';

    protected $description = 'Dispatch refresh jobs for all users with connected bank accounts';

    public function handle(): int
    {
        $totalUsers = User::query()->whereNotNull('basiq_user_id')->count();

        if ($totalUsers === 0) {
            $this->info('No users with connected bank accounts found.');

            return self::SUCCESS;
        }

        $dispatched = 0;

        User::query()
            ->whereNotNull('basiq_user_id')
            ->chunkById(100, function ($users) use (&$dispatched): void {
                foreach ($users as $user) {
                    $hasPendingRefresh = BasiqRefreshLog::query()
                        ->where('user_id', $user->id)
                        ->where('status', RefreshStatus::Pending)
                        ->exists();

                    if ($hasPendingRefresh) {
                        continue;
                    }

                    $log = BasiqRefreshLog::create([
                        'user_id' => $user->id,
                        'trigger' => RefreshTrigger::Scheduled,
                        'status' => RefreshStatus::Pending,
                    ]);

                    RefreshBasiqConnectionsJob::dispatch($user, $log)
                        ->delay(now()->addSeconds($dispatched * 10));

                    $dispatched++;
                }
            });

        $this->info("Dispatched refresh jobs for {$dispatched} of {$totalUsers} user(s).");

        return self::SUCCESS;
    }
}
