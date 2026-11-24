<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Models\PlannedTransaction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

final class GeneratePlannedOccurrencesCommand extends Command
{
    protected $signature = 'app:generate-planned-occurrences';

    protected $description = 'Queue the stored occurrence generation job for every user with an active plan';

    public function handle(): int
    {
        $queued = 0;

        User::query()
            ->whereIn('id', PlannedTransaction::query()->active()->select('user_id'))
            ->chunkById(100, function (Collection $users) use (&$queued): void {
                foreach ($users as $user) {
                    GeneratePlannedOccurrencesJob::dispatch($user);
                    $queued++;
                }
            });

        $this->info(sprintf('Queued occurrence generation for %d user(s).', $queued));

        return self::SUCCESS;
    }
}
