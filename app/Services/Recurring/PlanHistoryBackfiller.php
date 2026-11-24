<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\Jobs\RunTransactionAnalysisJob;
use App\Models\PlannedTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Stores the six months of past occurrences a newly accepted plan would have had, so its history shows real
 * cycles. Skipped while an analysis holds the user's lock; the accept has already succeeded either way.
 */
final readonly class PlanHistoryBackfiller
{
    public const int MONTHS = 6;

    public const int LOCK_SECONDS = 120;

    public function __construct(private PlannedOccurrenceGenerator $generator) {}

    public function run(PlannedTransaction $plan, User $user): void
    {
        $lock = Cache::lock(RunTransactionAnalysisJob::lockKey($user->id), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->generator->backfillHistory($plan, $user->today(), self::MONTHS);
        } finally {
            $lock->release();
        }
    }
}
