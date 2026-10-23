<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\User;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Backfills the daily balance history of every account, or of one user's accounts.
 * One account's failure is reported and the run carries on; the exit code is FAILURE
 * if any account failed.
 */
final class RebuildBalanceHistoryCommand extends Command
{
    protected $signature = 'balances:rebuild
        {--user= : Rebuild only this user id\'s accounts}';

    protected $description = 'Rebuild the daily balance history of accounts';

    public function handle(AccountBalanceHistoryBuilder $builder): int
    {
        $userId = $this->option('user');

        if ($userId !== null && ! User::query()->whereKey((int) $userId)->exists()) {
            $this->error("User {$userId} not found.");

            return self::FAILURE;
        }

        $rebuilt = 0;
        $failed = 0;

        Account::query()
            ->when($userId !== null, fn ($query) => $query->where('user_id', (int) $userId))
            ->chunkById(100, function (Collection $accounts) use ($builder, &$rebuilt, &$failed): void {
                foreach ($accounts as $account) {
                    try {
                        $builder->rebuild($account);
                        $rebuilt++;
                    } catch (Throwable $exception) {
                        $failed++;
                        $this->error("Account {$account->id}: ".$exception::class);
                        report($exception);
                    }
                }
            });

        $this->info("Rebuilt {$rebuilt} account(s); {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
