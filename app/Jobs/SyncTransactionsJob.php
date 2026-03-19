<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\BasiqServiceContract;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

final class SyncTransactionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $backoff = 5;

    public function __construct(
        public readonly string $jobId,
        public readonly User $user,
    ) {}

    /**
     * @throws ConnectionException
     */
    public function handle(BasiqServiceContract $basiqService): void
    {
        $job = $basiqService->getJob($this->jobId);

        if ($job->status === 'pending') {
            $this->release($this->backoff);

            return;
        }

        if ($job->status === 'failed') {
            Log::warning('Basiq job failed', ['jobId' => $this->jobId, 'userId' => $this->user->id]);

            return;
        }

        Log::info('Basiq job completed — transaction sync will be implemented in a future issue', [
            'jobId' => $this->jobId,
            'userId' => $this->user->id,
        ]);
    }
}
