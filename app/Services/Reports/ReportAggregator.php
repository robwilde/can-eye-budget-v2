<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class ReportAggregator
{
    private const int PLAN_OCCURRENCE_GUARD = 5000;

    /**
     * Flat aggregation of spend/income for a user, one row per
     * (month, direction, category) tuple. Actuals come from a single grouped
     * query; plans are expanded from their recurrence rule. Everything the
     * report renders (summary, monthly series, category roll-up, treemaps) is
     * derived from these atoms.
     *
     * @return list<array{ym: string, direction: string, category_id: int|null, total: int, count: int}>
     */
    public function atoms(User $user, string $mode, ?CarbonInterface $start, ?CarbonInterface $end): array
    {
        if ($mode === 'plan') {
            $now = CarbonImmutable::now();

            $windowStart = $start !== null
                ? CarbonImmutable::parse($start->toDateString())->startOfDay()
                : $now->startOfMonth();

            $windowEnd = $end !== null
                ? CarbonImmutable::parse($end->toDateString())->endOfDay()
                : $now->endOfDay();

            if ($windowEnd->lessThan($windowStart)) {
                $windowEnd = $windowStart->endOfDay();
            }

            return $this->plannedAtoms($user, $windowStart, $windowEnd);
        }

        return $this->actualAtoms($user, $start, $end);
    }

    /**
     * Concrete first-of-month / last-of-month bounds for the chart axis. Open
     * ranges resolve to the earliest recorded actual (or now) for real data and
     * to the current month for plans, which are forward-looking by nature.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function monthBounds(User $user, string $mode, ?CarbonInterface $start, ?CarbonInterface $end): array
    {
        $now = CarbonImmutable::now();

        if ($start !== null) {
            $startMonth = CarbonImmutable::parse($start->toDateString())->startOfMonth();
        } elseif ($mode === 'plan') {
            $startMonth = $now->startOfMonth();
        } else {
            $startMonth = ($this->earliestActualDate($user) ?? $now)->startOfMonth();
        }

        $endMonth = $end !== null
            ? CarbonImmutable::parse($end->toDateString())->endOfMonth()
            : $now->endOfMonth();

        if ($endMonth->lessThan($startMonth)) {
            $endMonth = $startMonth->endOfMonth();
        }

        return ['start' => $startMonth, 'end' => $endMonth];
    }

    /**
     * Inclusive list of `Y-m` keys spanning the two month bounds.
     *
     * @return list<string>
     */
    public function monthKeys(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $keys = [];
        $cursor = $start->startOfMonth();
        $last = $end->startOfMonth();
        $guard = 0;

        while ($cursor->lessThanOrEqualTo($last) && $guard < 600) {
            $keys[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonth();
            $guard++;
        }

        return $keys;
    }

    private function earliestActualDate(User $user): ?CarbonImmutable
    {
        $min = Transaction::query()
            ->where('user_id', $user->id)
            ->current()
            ->excludingTransfers()
            ->min('post_date');

        return $min === null ? null : CarbonImmutable::parse((string) $min);
    }

    /**
     * @return list<array{ym: string, direction: string, category_id: int|null, total: int, count: int}>
     */
    private function actualAtoms(User $user, ?CarbonInterface $start, ?CarbonInterface $end): array
    {
        $monthExpr = $this->monthExpression();

        $rows = Transaction::query()
            ->where('user_id', $user->id)
            ->current()
            ->excludingTransfers()
            ->when($start, fn ($q, $s) => $q->where('post_date', '>=', $s))
            ->when($end, fn ($q, $e) => $q->where('post_date', '<=', $e))
            ->selectRaw("{$monthExpr} as ym, direction, category_id, SUM(ABS(amount)) as total, COUNT(*) as tx_count")
            ->groupByRaw($monthExpr)
            ->groupBy('direction', 'category_id')
            ->get();

        $atoms = [];

        foreach ($rows as $row) {
            $atoms[] = [
                'ym' => (string) $row->getAttribute('ym'),
                'direction' => $row->direction->value,
                'category_id' => $row->category_id === null ? null : (int) $row->category_id,
                'total' => (int) $row->getAttribute('total'),
                'count' => (int) $row->getAttribute('tx_count'),
            ];
        }

        return $atoms;
    }

    /**
     * @return list<array{ym: string, direction: string, category_id: int|null, total: int, count: int}>
     */
    private function plannedAtoms(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $plans = PlannedTransaction::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->excludingTransfers()
            ->get();

        /** @var array<string, array{ym: string, direction: string, category_id: int|null, total: int, count: int}> $atoms */
        $atoms = [];

        foreach ($plans as $plan) {
            $amount = abs($plan->amount);

            if ($amount === 0) {
                continue;
            }

            $direction = $plan->direction->value;
            $categoryId = $plan->category_id;

            foreach ($this->planOccurrences($plan, $start, $end) as $date) {
                $ym = $date->format('Y-m');
                $key = $ym.'|'.$direction.'|'.($categoryId ?? 'null');

                if (! isset($atoms[$key])) {
                    $atoms[$key] = [
                        'ym' => $ym,
                        'direction' => $direction,
                        'category_id' => $categoryId,
                        'total' => 0,
                        'count' => 0,
                    ];
                }

                $atoms[$key]['total'] += $amount;
                $atoms[$key]['count']++;
            }
        }

        return array_values($atoms);
    }

    /**
     * Occurrences of a plan inside [$start, $end]. Jumping straight to the first
     * occurrence on or after $start means an old, high-frequency plan is never
     * silently truncated by a fixed cap that counts from start_date.
     *
     * @return list<CarbonImmutable>
     */
    private function planOccurrences(PlannedTransaction $plan, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $current = $plan->nextOccurrenceOnOrAfter($start);

        if ($current === null) {
            return [];
        }

        $dates = [];

        for ($i = 0; $i < self::PLAN_OCCURRENCE_GUARD; $i++) {
            if ($current->greaterThan($end)) {
                break;
            }

            if ($plan->until_date !== null && $current->greaterThan($plan->until_date)) {
                break;
            }

            $dates[] = $current;

            $next = $plan->frequency->nextOccurrence($current);

            if ($next === null) {
                break;
            }

            $current = $next;
        }

        return $dates;
    }

    private function monthExpression(): string
    {
        return Transaction::query()->getModel()->getConnection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', post_date)"
            : "DATE_FORMAT(post_date, '%Y-%m')";
    }
}
