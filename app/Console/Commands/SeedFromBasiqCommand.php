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
    private const string BASIQ_USER_ID = '3470f92c-54d1-4a68-a767-1d031d340d06';

    protected $signature = 'app:seed-from-basiq
        {--sync : Run synchronously instead of dispatching to queue}';

    protected $description = 'Seed the database with real Basiq sandbox data for the test user';

    public function handle(): int
    {
        $user = User::query()->where('email', 'test@example.com')->first();

        if (! $user) {
            $this->components->error('No test@example.com user found. Run the database seeder first.');

            return self::FAILURE;
        }

        $user->update([
            'basiq_user_id' => self::BASIQ_USER_ID,
            'last_synced_at' => null,
        ]);

        $this->components->info('Linked test@example.com to Basiq user: '.self::BASIQ_USER_ID);

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
