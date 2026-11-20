<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\GoalProgressDto;
use App\DTOs\GoalProjectionDto;
use App\Enums\GoalKind;
use App\Models\Goal;
use App\Models\GoalSpend;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The data behind a goal's projection chart: how the linked accounts' balances moved over the
 * last 90 days and where the goal's pace takes the saved amount by its target date. Reads only
 * the goal owner's accounts.
 */
final readonly class GoalProjection
{
    private const int WINDOW_DAYS = 90;

    private const int HORIZON_DAYS = 90;

    private const int DAYS_PER_MONTH = 30;

    public function __construct(private NetWorthService $netWorth, private GoalProgressCalculator $calculator) {}

    public function for(Goal $goal, GoalProgressDto $progress, ?CarbonImmutable $today = null): GoalProjectionDto
    {
        $today ??= $goal->user->today();
        $saved = $this->savedSeries($goal, $progress, $today);

        $projectedOn = $goal->target_date ?? $today->addDays(self::HORIZON_DAYS);
        $days = max(0, (int) $today->startOfDay()->diffInDays($projectedOn->startOfDay(), false));
        $projectedEnd = max(0, $progress->savedAmount + intdiv($progress->pacePerMonth * $days, self::DAYS_PER_MONTH));

        return new GoalProjectionDto(
            saved: $saved,
            targetAmount: $progress->targetAmount,
            projectedOn: $projectedOn,
            projectedEnd: $projectedEnd,
            shortfall: max(0, $progress->targetAmount - $projectedEnd),
        );
    }

    /**
     * The goal's saved amount day by day: each linked account's balance, carried across days
     * without history, goes through the same earmark and pro rata rules as the progress ring,
     * plus what has been spent against a one-off goal. The last day is the ring's saved amount,
     * and a completed goal stays flat at the amount it was frozen at.
     *
     * @return list<array{date: string, amount: int}>
     */
    private function savedSeries(Goal $goal, GoalProgressDto $progress, CarbonImmutable $today): array
    {
        $earmarks = $this->calculator->earmarksOf($goal);

        if ($earmarks === []) {
            return [];
        }

        $series = $this->netWorth->accountSeries($goal->user, array_keys($earmarks), $today->subDays(self::WINDOW_DAYS), $today);
        $dates = collect($series)->flatMap(static fn (array $days): array => array_keys($days))->unique()->sort()->values()->all();

        if ($dates === []) {
            return [];
        }

        $heldByOthers = $this->calculator->heldByOthersOf($goal);
        $spends = $goal->kind === GoalKind::OneOff ? GoalSpend::query()->where('goal_id', $goal->id)->get(['account_id', 'amount', 'created_at']) : new Collection;
        $timezone = $goal->user->resolvedTimezone();
        $saved = [];

        foreach ($dates as $date) {
            $balances = [];

            foreach ($series as $accountId => $days) {
                if (array_key_exists($date, $days)) {
                    $balances[$accountId] = $days[$date];
                }
            }

            $amount = $goal->frozen_saved_amount ?? array_sum($this->calculator->backingsFor($earmarks, $balances, $heldByOthers)) + $this->spentBy($spends, $earmarks, $date, $timezone);
            $saved[] = ['date' => $date, 'amount' => $amount];
        }

        $saved[array_key_last($saved)]['amount'] = $progress->savedAmount;

        return $saved;
    }

    /**
     * What counts as spent on the given date. A spend on an earmarked or unlinked account always
     * counts, because recording it lowered the earmark. A spend on a whole-balance account counts
     * only from the day it was recorded, since before that the balance still held the money.
     *
     * @param  Collection<int, GoalSpend>  $spends
     * @param  array<int, int|null>  $earmarks
     */
    private function spentBy(Collection $spends, array $earmarks, string $date, string $timezone): int
    {
        return (int) $spends
            ->reject(fn (GoalSpend $spend): bool => $spend->account_id !== null
                && array_key_exists($spend->account_id, $earmarks) && $earmarks[$spend->account_id] === null
                && $spend->created_at->toImmutable()->setTimezone($timezone)->toDateString() > $date)
            ->sum('amount');
    }
}
