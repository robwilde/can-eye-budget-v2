<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\DTOs\BalanceSheetGroup;
use App\Enums\ChartGranularity;
use App\Models\User;
use App\Services\NetWorthService;
use Carbon\CarbonImmutable;

/**
 * The reports page's net-worth section: what the user is worth now, what changed over the report
 * period, and the assets and liabilities by group with a series for each group. Every figure comes
 * from NetWorthService, so it agrees with the dashboard's net worth and balance sheet.
 *
 * @phpstan-type Group array{key: string, label: string, side: string, total: int, series: array<string, int>, favourable: bool}
 */
final readonly class NetWorthReport
{
    public function __construct(private NetWorthService $netWorth) {}

    /**
     * The range ends no later than today. The change is null when the balance history does not reach
     * back to the start of the range; with $fromEarliestHistory (All Time) it starts at the earliest
     * history day instead.
     *
     * @return array{from: string, to: string, current: int, start: int, end: int, change: int|null, assetTotal: int, liabilityTotal: int, groups: list<Group>}
     */
    public function build(User $user, CarbonImmutable $start, CarbonImmutable $end, bool $fromEarliestHistory): array
    {
        $to = $end->startOfDay()->min($user->today()->startOfDay());
        $from = $start->startOfDay()->min($to);
        $earliest = $fromEarliestHistory ? $this->netWorth->earliestHistoryDay($user) : null;

        if ($earliest !== null) {
            $from = $from->max($earliest)->min($to);
        }

        $sheet = $this->netWorth->balanceSheet($user);
        $days = $this->netWorth->plottedDays($from, $to, ChartGranularity::resolve($from, $to));
        $ends = $this->netWorth->series($user, $from, $to, flatBeforeHistory: true, onlyDays: [$from->toDateString(), $to->toDateString()]);
        $series = $this->netWorth->groupSeries($user, $sheet, $from, $to, $days);
        $startValue = $ends[$from->toDateString()];
        $endValue = $ends[$to->toDateString()];
        $covered = $fromEarliestHistory ? $earliest !== null : $this->netWorth->historyCovers($user, $from);

        $groups = [];

        foreach (['assets' => $sheet->assets, 'liabilities' => $sheet->liabilities] as $side => $sideGroups) {
            foreach ($sideGroups as $group) {
                $groups[] = $this->group($side, $group, $series[$side][$group->class->value] ?? []);
            }
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'current' => $sheet->netWorth(),
            'start' => $startValue,
            'end' => $endValue,
            'change' => $covered ? $endValue - $startValue : null,
            'assetTotal' => $sheet->assetTotal,
            'liabilityTotal' => $sheet->liabilityTotal,
            'groups' => $groups,
        ];
    }

    /**
     * @param  array<string, int>  $series
     * @return Group
     */
    private function group(string $side, BalanceSheetGroup $group, array $series): array
    {
        return [
            'key' => $side.'-'.$group->class->value,
            'label' => $group->class->label(),
            'side' => $side,
            'total' => $group->total,
            'series' => $series,
            'favourable' => $this->netWorth->isFavourable($series),
        ];
    }
}
