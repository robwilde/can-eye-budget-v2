<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncTransactionsJob;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Command;

final class SeedFromBasiqCommand extends Command
{
    protected $signature = 'app:seed-from-basiq
        {basiq_user_id? : The Basiq user ID to link (defaults to BASIQ_SEED_USER_ID env var)}
        {--sync : Run synchronously instead of dispatching to queue}';

    protected $description = 'Seed the database with real Basiq sandbox data for the test user';

    public function handle(): int
    {
        $basiqUserId = $this->argument('basiq_user_id') ?? config('services.basiq.seed_user_id');

        if (empty($basiqUserId)) {
            $this->components->error('No Basiq user ID provided. Pass it as an argument or set BASIQ_SEED_USER_ID in your .env file.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', 'test@example.com')->first();

        if (! $user) {
            $this->components->error('No test@example.com user found. Run the database seeder first.');

            return self::FAILURE;
        }

        $user->update([
            'basiq_user_id' => $basiqUserId,
            'last_synced_at' => null,
        ]);

        $this->components->info("Linked test@example.com to Basiq user: {$basiqUserId}");

        if ($this->option('sync')) {
            $this->components->info('Running sync synchronously...');
            SyncTransactionsJob::dispatchSync($user);

            $accountCount = Account::query()->where('user_id', $user->id)->count();
            $transactionCount = Transaction::query()->where('user_id', $user->id)->count();

            $this->components->info("Synced {$accountCount} accounts and {$transactionCount} transactions.");
        } else {
            SyncTransactionsJob::dispatch($user);
            $this->components->info('Dispatched SyncTransactionsJob to queue. Monitor progress in Horizon.');
        }

        return self::SUCCESS;
    }
}
