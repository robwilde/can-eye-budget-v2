<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\InsightGenerator;
use App\Models\User;
use App\Services\InsightGenerators\BudgetAtRiskGenerator;
use App\Services\InsightGenerators\BudgetOnTrackGenerator;
use App\Services\InsightGenerators\CashFlowWarningGenerator;
use App\Services\InsightGenerators\GoalDepletedGenerator;
use App\Services\InsightGenerators\IdleCashGenerator;
use App\Services\InsightGenerators\NetWorthMilestoneGenerator;
use App\Services\InsightGenerators\SavingsRateChangeGenerator;
use App\Services\InsightGenerators\SpendingAnomalyGenerator;
use App\Services\InsightGenerators\SubscriptionAuditGenerator;
use App\Services\InsightRecorder;
use App\Support\Audit\AttributeJobToUser;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Runs every insight generator for one user. A generator that throws is reported and
 * skipped: its insights are left as they are, and only the types of generators that
 * finished are allowed to expire.
 */
final class GenerateInsightsJob implements ShouldQueue
{
    use Queueable;

    /** @var list<class-string<InsightGenerator>> */
    private const array GENERATORS = [
        SpendingAnomalyGenerator::class,
        CashFlowWarningGenerator::class,
        SavingsRateChangeGenerator::class,
        IdleCashGenerator::class,
        BudgetAtRiskGenerator::class,
        BudgetOnTrackGenerator::class,
        GoalDepletedGenerator::class,
        SubscriptionAuditGenerator::class,
        NetWorthMilestoneGenerator::class,
    ];

    private const int LOCK_SECONDS = 120;

    private const int RETRY_WINDOW_MINUTES = 5;

    private const int OVERLAP_RELEASE_SECONDS = 10;

    public int $timeout = 60;

    public function __construct(
        public readonly User $user,
        public readonly ?string $runId = null,
    ) {}

    public static function runKey(string $runId): string
    {
        return "insights:run:{$runId}";
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::RETRY_WINDOW_MINUTES);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            new AttributeJobToUser($this->user->id),
            new WithoutOverlapping("generate-insights-{$this->user->id}")->expireAfter(self::LOCK_SECONDS)->releaseAfter(self::OVERLAP_RELEASE_SECONDS),
        ];
    }

    public function handle(InsightRecorder $recorder): void
    {
        if (config('budget.insights_enabled') !== true) {
            $this->markRun('done');

            return;
        }

        $ranTypes = [];
        $keys = [];

        foreach (self::GENERATORS as $class) {
            try {
                $generator = resolve($class);
                $generated = $generator->generate($this->user);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            array_push($ranTypes, ...$generator->types());
            array_push($keys, ...$generated);
        }

        $recorder->expireMissing($this->user, $ranTypes, $keys);

        $this->markRun('done');
    }

    public function failed(Throwable $exception): void
    {
        $this->markRun('failed');
    }

    private function markRun(string $outcome): void
    {
        if ($this->runId !== null) {
            Cache::put(self::runKey($this->runId), $outcome, now()->addHour());
        }
    }
}
