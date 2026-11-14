<?php

declare(strict_types=1);

namespace App\Services\InsightGenerators;

use App\Contracts\InsightGenerator;
use App\Enums\GoalHealth;
use App\Enums\GoalKind;
use App\Enums\GoalStatus;
use App\Enums\InsightPriority;
use App\Enums\InsightType;
use App\Models\Goal;
use App\Models\User;
use App\Services\GoalProgressCalculator;
use App\Services\InsightRecorder;
use App\Support\Insights\InsightMoney;

/**
 * High-priority insights for the user's active maintained reserves that have fallen below
 * their floor, largest shortfall first, at most two. Does nothing while goals are disabled.
 */
final readonly class GoalDepletedGenerator implements InsightGenerator
{
    private const int LIMIT = 2;

    private const int SHORTFALL_BAND_CENTS = 10000;

    public function __construct(
        private InsightRecorder $recorder,
        private GoalProgressCalculator $calculator,
    ) {}

    public function key(): string
    {
        return 'goal-depleted';
    }

    public function types(): array
    {
        return [InsightType::GoalDepleted];
    }

    public function generate(User $user): array
    {
        if (config('budget.goals_enabled') !== true) {
            return [];
        }

        $today = $user->today();
        $depleted = [];

        $reserves = Goal::query()
            ->where('user_id', $user->id)
            ->where('kind', GoalKind::Reserve)
            ->where('status', GoalStatus::Active)
            ->orderBy('id')
            ->get();

        foreach ($reserves as $reserve) {
            $progress = $this->calculator->calculate($reserve, $today);

            if ($progress->health === GoalHealth::Depleted) {
                $depleted[] = ['goal' => $reserve, 'progress' => $progress];
            }
        }

        usort($depleted, fn (array $a, array $b): int => [-$a['progress']->remainingAmount, $a['goal']->id] <=> [-$b['progress']->remainingAmount, $b['goal']->id]);

        $keys = [];

        foreach (array_slice($depleted, 0, self::LIMIT) as ['goal' => $goal, 'progress' => $progress]) {
            $key = "goal_depleted:{$goal->id}";

            $this->recorder->record(
                user: $user,
                type: InsightType::GoalDepleted,
                priority: InsightPriority::High,
                dedupKey: $key,
                title: "{$goal->name} is below its floor",
                body: sprintf(
                    '%s holds %s, %s short of its %s floor.',
                    $goal->name,
                    InsightMoney::format($progress->savedAmount, $user->base_currency),
                    InsightMoney::format($progress->remainingAmount, $user->base_currency),
                    InsightMoney::format($progress->targetAmount, $user->base_currency),
                ),
                figures: [
                    'goal_id' => $goal->id,
                    'saved_cents' => $progress->savedAmount,
                    'target_cents' => $progress->targetAmount,
                    'shortfall_cents' => $progress->remainingAmount,
                ],
                comparison: [
                    'goal_id' => $goal->id,
                    'shortfall_band' => intdiv($progress->remainingAmount, self::SHORTFALL_BAND_CENTS),
                ],
            );

            $keys[] = $key;
        }

        return $keys;
    }
}
