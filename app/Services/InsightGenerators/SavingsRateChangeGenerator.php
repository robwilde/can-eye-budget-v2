<?php

declare(strict_types=1);

namespace App\Services\InsightGenerators;

use App\Contracts\InsightGenerator;
use App\Enums\InsightPriority;
use App\Enums\InsightType;
use App\Enums\TransactionDirection;
use App\Models\User;
use App\Services\InsightRecorder;
use App\Support\Insights\CountableFlow;
use Carbon\CarbonImmutable;

final readonly class SavingsRateChangeGenerator implements InsightGenerator
{
    private const float THRESHOLD_POINTS = 5.0;

    private const float HIGH_POINTS = 10.0;

    public function __construct(private InsightRecorder $recorder) {}

    public function key(): string
    {
        return 'savings-rate-change';
    }

    public function types(): array
    {
        return [InsightType::SavingsRateChange];
    }

    public function generate(User $user): array
    {
        $latestStart = $user->today()->startOfMonth()->subMonthNoOverflow();
        $previousStart = $latestStart->subMonthNoOverflow();

        $latest = $this->rate($user, $latestStart);
        $previous = $this->rate($user, $previousStart);

        if ($latest === null || $previous === null) {
            return [];
        }

        $change = round($latest - $previous, 1);

        if (abs($change) < self::THRESHOLD_POINTS) {
            return [];
        }

        $month = $latestStart->format('Y-m');
        $key = "savings_rate_change:{$month}";
        $direction = $change > 0 ? 'up' : 'down';

        $this->recorder->record(
            user: $user,
            type: InsightType::SavingsRateChange,
            priority: abs($change) >= self::HIGH_POINTS ? InsightPriority::High : InsightPriority::Medium,
            dedupKey: $key,
            title: sprintf('Your savings rate is %s %s points', $direction, mb_rtrim(mb_rtrim(number_format(abs($change), 1), '0'), '.')),
            body: sprintf(
                'You saved %s%% of your income in %s, compared with %s%% in %s.',
                mb_rtrim(mb_rtrim(number_format($latest, 1), '0'), '.'),
                $latestStart->format('F'),
                mb_rtrim(mb_rtrim(number_format($previous, 1), '0'), '.'),
                $previousStart->format('F'),
            ),
            figures: [
                'month' => $month,
                'rate' => round($latest, 1),
                'previous_rate' => round($previous, 1),
                'change_points' => $change,
            ],
            comparison: [
                'month' => $month,
                'severity' => abs($change) >= self::HIGH_POINTS ? 'high' : 'medium',
                'rate_tenths' => (int) round($latest * 10),
                'previous_rate_tenths' => (int) round($previous * 10),
            ],
            actionRoute: 'reports',
        );

        return [$key];
    }

    private function rate(User $user, CarbonImmutable $start): ?float
    {
        $end = $start->endOfMonth();
        $income = CountableFlow::total($user, TransactionDirection::Credit, $start, $end);

        if ($income <= 0) {
            return null;
        }

        $spend = CountableFlow::total($user, TransactionDirection::Debit, $start, $end);

        return ($income - $spend) / $income * 100;
    }
}
