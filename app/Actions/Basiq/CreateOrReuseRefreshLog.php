<?php

declare(strict_types=1);

namespace App\Actions\Basiq;

use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use App\Jobs\SyncTransactionsJob;
use App\Models\BasiqRefreshLog;
use App\Models\User;

final class CreateOrReuseRefreshLog
{
    public function __invoke(User $user, RefreshTrigger $trigger, ?string $jobId = null): BasiqRefreshLog
    {
        $existing = BasiqRefreshLog::query()
            ->where('user_id', $user->id)
            ->where('status', RefreshStatus::Pending)
            ->where('created_at', '>', now()->subSeconds(SyncTransactionsJob::UNIQUE_FOR))
            ->latest()
            ->first();

        return $existing ?? BasiqRefreshLog::create([
            'user_id' => $user->id,
            'trigger' => $trigger,
            'status' => RefreshStatus::Pending,
            'job_ids' => $jobId !== null ? [$jobId] : [],
        ]);
    }
}
