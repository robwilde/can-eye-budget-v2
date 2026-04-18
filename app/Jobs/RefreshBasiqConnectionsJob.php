<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\BasiqServiceContract;
use App\Enums\RefreshStatus;
use App\Models\BasiqRefreshLog;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RefreshBasiqConnectionsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public int $backoff = 10;

    public int $timeout = 180;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly User $user,
        public readonly BasiqRefreshLog $log,
    ) {}

    public function uniqueId(): int
    {
        return $this->user->id;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping("refresh-user-{$this->user->id}"),
        ];
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function handle(BasiqServiceContract $basiqService): void
    {
        try {
            $this->process($basiqService);
        } catch (RequestException $e) {
            if ($e->response->status() === 404) {
                Log::error('Basiq user not found (404). Clearing stale basiq_user_id.', [
                    'userId' => $this->user->id,
                    'basiqUserId' => $this->user->basiq_user_id,
                ]);

                $this->user->update(['basiq_user_id' => null]);
                $this->log->update(['status' => RefreshStatus::Failed]);
                $this->fail($e);

                return;
            }

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->log->update(['status' => RefreshStatus::Failed]);

        Log::error('RefreshBasiqConnectionsJob failed', [
            'userId' => $this->user->id,
            'logId' => $this->log->id,
            'exception' => $exception,
        ]);
    }

    /**
     * @throws RequestException|ConnectionException
     */
    private function process(BasiqServiceContract $basiqService): void
    {
        if ($this->log->job_ids === null) {
            $jobIds = $basiqService->refreshConnections($this->user->basiq_user_id);
            $this->log->update(['job_ids' => $jobIds]);
        }

        foreach ($this->log->job_ids as $jobId) {
            $job = $basiqService->getJob($jobId);

            if ($job->status === 'failed') {
                $this->log->update(['status' => RefreshStatus::Failed]);

                return;
            }

            if ($job->status === 'pending') {
                $this->release($this->backoff);

                return;
            }
        }

        SyncTransactionsJob::dispatch($this->user);

        $this->log->update([
            'status' => RefreshStatus::Success,
            'accounts_synced' => $this->user->accounts()->count(),
        ]);
    }
}
