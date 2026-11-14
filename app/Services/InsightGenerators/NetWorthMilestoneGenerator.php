<?php

declare(strict_types=1);

namespace App\Services\InsightGenerators;

use App\Contracts\InsightGenerator;
use App\Enums\InsightPriority;
use App\Enums\InsightStatus;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\User;
use App\Services\InsightRecorder;
use App\Services\NetWorthService;
use App\Support\Insights\InsightMoney;
use Carbon\CarbonImmutable;

/**
 * One insight per net-worth threshold the user's net worth crossed upward within the last
 * 30 days, read from the daily balance history through NetWorthService::series().
 *
 * Thresholds, in dollars: 10k, 25k, 50k, 100k, 250k, 500k, 1M, 2.5M, 5M, 10M, that is 1, 2.5
 * and 5 times each power of ten from 10k up to 1M, then 10M. The dedup key is the threshold
 * and the compared value is the threshold alone. The run also returns the key of every
 * acknowledged milestone insight the user already has, so the job never expires one: an
 * acknowledged milestone stays acknowledged when its crossing leaves the 30-day window and
 * when net worth later dips and crosses the threshold again. Milestones that were never
 * acknowledged expire normally once their crossing leaves the window.
 */
final readonly class NetWorthMilestoneGenerator implements InsightGenerator
{
    public const array THRESHOLDS_CENTS = [
        1_000_000,
        2_500_000,
        5_000_000,
        10_000_000,
        25_000_000,
        50_000_000,
        100_000_000,
        250_000_000,
        500_000_000,
        1_000_000_000,
    ];

    private const int WINDOW_DAYS = 30;

    public function __construct(
        private InsightRecorder $recorder,
        private NetWorthService $netWorth,
    ) {}

    public function key(): string
    {
        return 'net-worth-milestone';
    }

    public function types(): array
    {
        return [InsightType::NetWorthMilestone];
    }

    public function generate(User $user): array
    {
        $today = $user->today();
        $series = $this->netWorth->series($user, $today->subDays(self::WINDOW_DAYS), $today);
        $keys = [];

        foreach (self::THRESHOLDS_CENTS as $threshold) {
            $crossedOn = $this->lastCrossing($series, $threshold);

            if ($crossedOn === null) {
                continue;
            }

            $key = "net_worth_milestone:{$threshold}";

            $this->recorder->record(
                user: $user,
                type: InsightType::NetWorthMilestone,
                priority: InsightPriority::Low,
                dedupKey: $key,
                title: 'Your net worth passed '.InsightMoney::format($threshold, $user->base_currency),
                body: sprintf(
                    'Your net worth crossed %s on %s and is now %s.',
                    InsightMoney::format($threshold, $user->base_currency),
                    CarbonImmutable::parse($crossedOn)->format('j M'),
                    InsightMoney::format((int) end($series), $user->base_currency),
                ),
                figures: [
                    'threshold_cents' => $threshold,
                    'crossed_on' => $crossedOn,
                    'net_worth_cents' => (int) end($series),
                ],
                comparison: [
                    'threshold_cents' => $threshold,
                ],
                actionRoute: 'accounts',
            );

            $keys[] = $key;
        }

        $acknowledged = Insight::query()
            ->where('user_id', $user->id)
            ->where('type', InsightType::NetWorthMilestone)
            ->where('status', InsightStatus::Acknowledged)
            ->pluck('dedup_key')
            ->all();

        return array_values(array_unique([...$keys, ...$acknowledged]));
    }

    /**
     * The latest day whose balance reached the threshold after a day below it, or null.
     *
     * @param  array<string, int>  $series  net worth by Y-m-d, oldest first
     */
    private function lastCrossing(array $series, int $threshold): ?string
    {
        $crossedOn = null;
        $previous = null;

        foreach ($series as $day => $netWorth) {
            if ($previous !== null && $previous < $threshold && $netWorth >= $threshold) {
                $crossedOn = $day;
            }

            $previous = $netWorth;
        }

        return $crossedOn;
    }
}
