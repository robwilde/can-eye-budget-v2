<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PipelineTrigger;
use App\Models\User;
use App\Services\TransactionAnalysisPipeline;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RunTransactionAnalysisJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly User $user,
    ) {}

    public function uniqueId(): int
    {
        return $this->user->id;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping("analysis-user-{$this->user->id}"),
        ];
    }

    public function handle(TransactionAnalysisPipeline $pipeline): void
    {
        $pipeline->run($this->user, PipelineTrigger::Sync);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('RunTransactionAnalysisJob failed', [
            'userId' => $this->user->id,
            'exception' => $exception,
        ]);
    }
}
