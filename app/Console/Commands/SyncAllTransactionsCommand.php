<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncTransactionsJob;
use App\Models\User;
use Illuminate\Console\Command;

final class SyncAllTransactionsCommand extends Command
{
    protected $signature = 'app:sync-all-transactions';

    protected $description = 'Dispatch transaction sync jobs for all users with connected bank accounts';

    public function handle(): int
    {
        $totalUsers = User::query()
            ->whereNotNull('basiq_user_id')
            ->count();

        if ($totalUsers === 0) {
            $this->info('No users with connected bank accounts found.');

            return self::SUCCESS;
        }

        $index = 0;

        User::query()
            ->whereNotNull('basiq_user_id')
            ->chunkById(100, function ($users) use (&$index): void {
                foreach ($users as $user) {
                    SyncTransactionsJob::dispatch($user)
                        ->delay(now()->addSeconds($index * 10));

                    $index++;
                }
            });

        $this->info("Dispatched sync jobs for {$totalUsers} user(s).");

        return self::SUCCESS;
    }
}
