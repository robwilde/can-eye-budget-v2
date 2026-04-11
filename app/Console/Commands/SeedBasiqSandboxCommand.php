<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\BasiqServiceContract;
use App\Jobs\SyncTransactionsJob;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

final class SeedBasiqSandboxCommand extends Command
{
    /** @var array<int, array{name: string, email: string, loginId: string, password: string, institutionId: string}> */
    private const array SANDBOX_USERS = [
        ['name' => 'Max Wentworth-Smith', 'email' => 'maxsmith@micr0soft.com', 'loginId' => 'Wentworth-Smith', 'password' => 'whislter', 'institutionId' => 'AU00000'],
        ['name' => 'Whistler Smith', 'email' => 'whistler@h0tmail.com', 'loginId' => 'Whistler', 'password' => 'ShowBox', 'institutionId' => 'AU00000'],
        ['name' => 'Gilfoyle Bertram', 'email' => 'gilfoyle@mgail.com', 'loginId' => 'Gilfoyle', 'password' => 'PiedPiper', 'institutionId' => 'AU00000'],
        ['name' => 'Gavin Belson', 'email' => 'gavinbelson@h0tmail.com', 'loginId' => 'gavinBelson', 'password' => 'hooli2016', 'institutionId' => 'AU00004'],
        ['name' => 'Jared Dunn', 'email' => 'Jared.D@h0tmail.com', 'loginId' => 'jared', 'password' => 'django', 'institutionId' => 'AU00000'],
        ['name' => 'Richard Birtles', 'email' => 'r.birtles@tetlerjones.c0m.au', 'loginId' => 'richard', 'password' => 'tabsnotspaces', 'institutionId' => 'AU00000'],
        ['name' => 'Laurie Bream', 'email' => 'business@manlyaccountants.com.au', 'loginId' => 'laurieBream', 'password' => 'business2024', 'institutionId' => 'AU00000'],
        ['name' => 'Ash Mann', 'email' => 'ashmann@gamil.com', 'loginId' => 'ashMann', 'password' => 'hooli2024', 'institutionId' => 'AU00000'],
    ];

    protected $signature = 'app:seed-basiq-sandbox
        {--fresh : Run migrate:fresh --seed first}
        {--skip-sync : Skip transaction sync after connecting}';

    protected $description = 'Provision all 8 Basiq sandbox users with bank connections and synced transactions';

    public function handle(BasiqServiceContract $basiqService): int
    {
        if ($this->option('fresh')) {
            $this->components->info('Running migrate:fresh --seed...');
            $this->call('migrate:fresh', ['--seed' => true]);
        }

        $results = [];

        foreach (self::SANDBOX_USERS as $index => $sandbox) {
            $user = User::query()->where('email', $sandbox['email'])->first();

            if (! $user) {
                $results[] = [$sandbox['name'], $sandbox['email'], '<fg=red>User not found</>'];

                continue;
            }

            try {
                $status = $this->provisionUser($basiqService, $user, $sandbox);
                $results[] = [$sandbox['name'], $sandbox['email'], $status];
            } catch (Throwable $e) {
                $results[] = [$sandbox['name'], $sandbox['email'], "<fg=red>Failed: {$e->getMessage()}</>"];
            }

            if ($index < count(self::SANDBOX_USERS) - 1) {
                Sleep::for(3)->seconds();
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan;options=bold>Name</>', '<fg=cyan;options=bold>Status</>');

        foreach ($results as $row) {
            $this->components->twoColumnDetail("{$row[0]} ({$row[1]})", $row[2]);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{name: string, email: string, loginId: string, password: string, institutionId: string}  $sandbox
     */
    private function provisionUser(BasiqServiceContract $basiqService, User $user, array $sandbox): string
    {
        $this->components->task("Registering {$sandbox['name']} with Basiq", function () use ($basiqService, $user, $sandbox) {
            $basiqUser = $basiqService->createUser($sandbox['email']);
            $user->update(['basiq_user_id' => $basiqUser->id, 'last_synced_at' => null]);
        });

        $this->components->task("Creating connection ({$sandbox['institutionId']})", function () use ($basiqService, $user, $sandbox) {
            $this->connectWithRetry($basiqService, $user, $sandbox);
        });

        if (! $this->option('skip-sync')) {
            $this->components->task('Syncing transactions', function () use ($user) {
                SyncTransactionsJob::dispatchSync($user->refresh());
            });

            $accountCount = $user->accounts()->count();

            return "<fg=green>OK</> ({$accountCount} accounts)";
        }

        return '<fg=green>OK</> (sync skipped)';
    }

    /** @param  array{name: string, email: string, loginId: string, password: string, institutionId: string}  $sandbox */
    private function connectWithRetry(BasiqServiceContract $basiqService, User $user, array $sandbox, int $maxRetries = 2): void
    {
        for ($retry = 1; $retry <= $maxRetries; $retry++) {
            $jobId = $basiqService->createConnection(
                $user->basiq_user_id,
                $sandbox['institutionId'],
                $sandbox['loginId'],
                $sandbox['password'],
            );

            try {
                $this->pollJob($basiqService, $jobId);

                return;
            } catch (RuntimeException $e) {
                if ($retry === $maxRetries) {
                    throw $e;
                }
                $this->components->warn("Connection attempt {$retry} failed, retrying...");
                Sleep::for(3)->seconds();
            }
        }
    }

    /**
     * @throws RuntimeException
     * @throws RequestException
     * @throws ConnectionException
     */
    private function pollJob(BasiqServiceContract $basiqService, string $jobId): void
    {
        $maxAttempts = 20;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $job = $basiqService->getJob($jobId);

            if ($job->status === 'success') {
                return;
            }

            if ($job->status === 'failed') {
                throw new RuntimeException("Basiq job {$jobId} failed");
            }

            Sleep::for(2)->seconds();
        }

        throw new RuntimeException("Basiq job {$jobId} timed out after {$maxAttempts} attempts");
    }
}
