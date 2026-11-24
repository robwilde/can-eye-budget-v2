<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\Enums\PipelineRunStatus;
use App\Enums\PipelineTrigger;
use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Jobs\RunTransactionAnalysisJob;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The on-demand recurring scan the rules page and the recurring settings page run. It takes the same per-user
 * lock as the analysis job, so a scan and an analysis never overlap, and replaces the pending suggestions of what
 * it scanned with the new ones.
 */
final readonly class RecurringScanner
{
    public const int LOCK_SECONDS = 120;

    public function __construct(private RecurringTransactionDetector $detector, private RecurringSuggestionWriter $writer) {}

    /**
     * @param  int|null  $accountId  one account, or every account when null
     * @return int|null how many suggestions were created, or null when an analysis holds the user's lock
     */
    public function run(User $user, ?int $accountId = null): ?int
    {
        $lock = Cache::lock(RunTransactionAnalysisJob::lockKey($user->id), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            return count($this->scan($user, $accountId));
        } finally {
            $lock->release();
        }
    }

    /** @return list<int> */
    private function scan(User $user, ?int $accountId): array
    {
        $candidates = $this->detector->detect($user, $accountId);

        /** @var list<int> $ids */
        $ids = DB::transaction(function () use ($user, $candidates, $accountId): array {
            $run = PipelineRun::create([
                'user_id' => $user->id,
                'trigger' => PipelineTrigger::Manual,
                'status' => PipelineRunStatus::Running,
                'started_at' => CarbonImmutable::now(),
            ]);

            AnalysisSuggestion::query()
                ->where('user_id', $user->id)
                ->ofType(SuggestionType::RecurringTransaction)
                ->pending()
                ->when($accountId !== null, fn ($query) => $query->where('payload->account_id', $accountId))
                ->update([
                    'status' => SuggestionStatus::Superseded,
                    'resolved_at' => CarbonImmutable::now(),
                ]);

            $ids = $this->writer->writeForRun($user, $run, $candidates);

            $run->update([
                'status' => PipelineRunStatus::Completed,
                'completed_at' => CarbonImmutable::now(),
            ]);

            return $ids;
        });

        return $ids;
    }
}
