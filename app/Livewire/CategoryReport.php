<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Enums\TransactionDirection;
use App\Enums\TransactionPeriod;
use App\Models\Category;
use App\Models\User;
use App\Services\Reports\ReportAggregator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Exception;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

final class CategoryReport extends Component
{
    private const array VALID_MODES = ['real', 'plan'];

    private const int TOP_LIMIT = 5;

    private const array FALLBACK_COLORS = [
        '#6366F1', '#8B5CF6', '#EC4899', '#F43F5E',
        '#F97316', '#EAB308', '#22C55E', '#14B8A6',
        '#06B6D4', '#3B82F6', '#A855F7', '#78716C',
    ];

    #[Url]
    public string $period = 'this-month';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    #[Url]
    public string $mode = 'real';

    #[Url]
    public bool $topOnly = false;

    #[Url]
    public bool $showSubcategories = false;

    /** @var list<int> */
    public array $expanded = [];

    /** @var array<int, array{id: int, name: string, parent_id: int|null, icon: string|null, color: string|null}>|null */
    private ?array $categoryMap = null;

    /** @var array{start: CarbonInterface|null, end: CarbonInterface|null}|null */
    private ?array $datesCache = null;

    /** @var list<array{ym: string, direction: string, category_id: int|null, total: int, count: int}>|null */
    private ?array $atomsCache = null;

    /** @var array{start: CarbonImmutable, end: CarbonImmutable}|null */
    private ?array $boundsCache = null;

    private ?int $monthCountCache = null;

    /** @var array<string, array{buckets: list<array{id: int|null, name: string, icon: string|null, color: string, total: int, count: int, pct: float, perMonth: int, bar: int, expandable: bool, children: list<array{id: int, path: string, total: int, count: int, pct: float, perMonth: int, bar: int}>}>, sum: int, max: int}> */
    private array $bucketsCache = [];

    public function mount(): void
    {
        if (! TransactionPeriod::tryFrom($this->period)) {
            $this->period = 'this-month';
        }

        if (! in_array($this->mode, self::VALID_MODES, true)) {
            $this->mode = 'real';
        }
    }

    public function updatedPeriod(): void
    {
        if (! TransactionPeriod::tryFrom($this->period)) {
            $this->period = 'this-month';
        }

        $this->refreshCharts();
    }

    public function updatedMode(): void
    {
        if (! in_array($this->mode, self::VALID_MODES, true)) {
            $this->mode = 'real';
        }

        $this->refreshCharts();
    }

    public function updatedFrom(): void
    {
        $this->from = $this->normaliseDate($this->from);
        $this->refreshCharts();
    }

    public function updatedTo(): void
    {
        $this->to = $this->normaliseDate($this->to);
        $this->refreshCharts();
    }

    public function toggleExpand(int $categoryId): void
    {
        if (in_array($categoryId, $this->expanded, true)) {
            $this->expanded = array_values(array_filter(
                $this->expanded,
                static fn (int $id): bool => $id !== $categoryId,
            ));

            return;
        }

        $this->expanded[] = $categoryId;
    }

    /**
     * @return array{in: int, out: int, net: int, months: int, inPerMonth: int, outPerMonth: int, netPerMonth: int, outPctIncome: int|null, netPctIncome: int|null}
     */
    #[Computed]
    public function summary(): array
    {
        $in = 0;
        $out = 0;

        foreach ($this->atoms() as $atom) {
            if ($atom['direction'] === TransactionDirection::Credit->value) {
                $in += $atom['total'];
            } else {
                $out += $atom['total'];
            }
        }

        $months = $this->monthCount();
        $net = $in - $out;

        return [
            'in' => $in,
            'out' => $out,
            'net' => $net,
            'months' => $months,
            'inPerMonth' => intdiv($in, $months),
            'outPerMonth' => intdiv($out, $months),
            'netPerMonth' => intdiv($net, $months),
            'outPctIncome' => $in > 0 ? (int) round($out / $in * 100) : null,
            'netPctIncome' => $in > 0 ? (int) round($net / $in * 100) : null,
        ];
    }

    /**
     * @return array{buckets: list<array{id: int|null, name: string, icon: string|null, color: string, total: int, count: int, pct: float, perMonth: int, bar: int, expandable: bool, children: list<array{id: int, path: string, total: int, count: int, pct: float, perMonth: int, bar: int}>}>, sum: int, max: int}
     */
    #[Computed]
    public function expenses(): array
    {
        return $this->buckets(TransactionDirection::Debit->value);
    }

    /**
     * @return array{buckets: list<array{id: int|null, name: string, icon: string|null, color: string, total: int, count: int, pct: float, perMonth: int, bar: int, expandable: bool, children: list<array{id: int, path: string, total: int, count: int, pct: float, perMonth: int, bar: int}>}>, sum: int, max: int}
     */
    #[Computed]
    public function incomes(): array
    {
        return $this->buckets(TransactionDirection::Credit->value);
    }

    /**
     * @return list<array{id: int|null, name: string, icon: string|null, color: string, total: int, count: int, pct: float, perMonth: int, bar: int, expandable: bool, children: list<array{id: int, path: string, total: int, count: int, pct: float, perMonth: int, bar: int}>}>
     */
    #[Computed]
    public function expenseRows(): array
    {
        return $this->limitBuckets($this->buckets(TransactionDirection::Debit->value)['buckets']);
    }

    /**
     * @return list<array{id: int|null, name: string, icon: string|null, color: string, total: int, count: int, pct: float, perMonth: int, bar: int, expandable: bool, children: list<array{id: int, path: string, total: int, count: int, pct: float, perMonth: int, bar: int}>}>
     */
    #[Computed]
    public function incomeRows(): array
    {
        return $this->limitBuckets($this->buckets(TransactionDirection::Credit->value)['buckets']);
    }

    /**
     * @return array{labels: list<string>, income: list<int>, expense: list<int>, net: list<int>}
     */
    #[Computed]
    public function chart(): array
    {
        $bounds = $this->bounds();
        $keys = $this->aggregator()->monthKeys($bounds['start'], $bounds['end']);

        /** @var array<string, array<string, int>> $byMonth */
        $byMonth = [];

        foreach ($this->atoms() as $atom) {
            $byMonth[$atom['ym']][$atom['direction']] = ($byMonth[$atom['ym']][$atom['direction']] ?? 0) + $atom['total'];
        }

        $labels = [];
        $income = [];
        $expense = [];
        $net = [];

        foreach ($keys as $key) {
            $labels[] = CarbonImmutable::parse($key.'-01')->format('M y');
            $in = $byMonth[$key][TransactionDirection::Credit->value] ?? 0;
            $out = $byMonth[$key][TransactionDirection::Debit->value] ?? 0;
            $income[] = $in;
            $expense[] = $out;
            $net[] = $in - $out;
        }

        return ['labels' => $labels, 'income' => $income, 'expense' => $expense, 'net' => $net];
    }

    /**
     * @return array{expense: list<array{x: string, y: int, color: string}>, income: list<array{x: string, y: int, color: string}>}
     */
    #[Computed]
    public function treemap(): array
    {
        return [
            'expense' => $this->treemapNodes($this->buckets(TransactionDirection::Debit->value)['buckets']),
            'income' => $this->treemapNodes($this->buckets(TransactionDirection::Credit->value)['buckets']),
        ];
    }

    public function render(): View
    {
        $periodEnum = TransactionPeriod::tryFrom($this->period) ?? TransactionPeriod::ThisMonth;

        return view('livewire.category-report', [
            'formatMoney' => MoneyCast::format(...),
            'periodLabel' => $periodEnum->label(),
            'hasPayCycle' => $this->user()->hasPayCycleConfigured(),
            'showCustomRange' => $periodEnum === TransactionPeriod::Custom,
            'topLimit' => self::TOP_LIMIT,
        ]);
    }

    private function refreshCharts(): void
    {
        $this->dispatch('reports:refresh', chart: $this->chart(), treemap: $this->treemap());
    }

    /**
     * @return array{start: CarbonInterface|null, end: CarbonInterface|null}
     */
    private function dates(): array
    {
        if ($this->datesCache !== null) {
            return $this->datesCache;
        }

        $periodEnum = TransactionPeriod::tryFrom($this->period) ?? TransactionPeriod::ThisMonth;

        return $this->datesCache = $periodEnum->dateRange($this->user(), $this->from, $this->to);
    }

    /**
     * @return list<array{ym: string, direction: string, category_id: int|null, total: int, count: int}>
     */
    private function atoms(): array
    {
        if ($this->atomsCache !== null) {
            return $this->atomsCache;
        }

        $dates = $this->dates();

        return $this->atomsCache = $this->aggregator()->atoms($this->user(), $this->mode, $dates['start'], $dates['end']);
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    private function bounds(): array
    {
        if ($this->boundsCache !== null) {
            return $this->boundsCache;
        }

        $dates = $this->dates();

        return $this->boundsCache = $this->aggregator()->monthBounds($this->user(), $this->mode, $dates['start'], $dates['end']);
    }

    private function monthCount(): int
    {
        if ($this->monthCountCache !== null) {
            return $this->monthCountCache;
        }

        $bounds = $this->bounds();

        return $this->monthCountCache = max(1, count($this->aggregator()->monthKeys($bounds['start'], $bounds['end'])));
    }

    /**
     * Roll a direction's per-category totals up to their root categories,
     * attaching each root's full-path descendants as inline children (the root's
     * own directly-tagged spend stays in the root total but is not repeated as a
     * child row). A cyclic chain, or a transaction whose category no longer
     * exists, collapses into a single uncategorised bucket.
     *
     * @return array{buckets: list<array{id: int|null, name: string, icon: string|null, color: string, total: int, count: int, pct: float, perMonth: int, bar: int, expandable: bool, children: list<array{id: int, path: string, total: int, count: int, pct: float, perMonth: int, bar: int}>}>, sum: int, max: int}
     */
    private function buckets(string $directionValue): array
    {
        if (isset($this->bucketsCache[$directionValue])) {
            return $this->bucketsCache[$directionValue];
        }

        $map = $this->categoryMap();
        $months = $this->monthCount();

        /** @var array<int|string, array{id: int|null, total: int, count: int}> $totals */
        $totals = [];

        foreach ($this->atoms() as $atom) {
            if ($atom['direction'] !== $directionValue) {
                continue;
            }

            $categoryId = $atom['category_id'];
            $key = $categoryId ?? 'uncat';

            if (! isset($totals[$key])) {
                $totals[$key] = ['id' => $categoryId, 'total' => 0, 'count' => 0];
            }

            $totals[$key]['total'] += $atom['total'];
            $totals[$key]['count'] += $atom['count'];
        }

        /** @var array<int|string, array{id: int|null, total: int, count: int, members: list<array{id: int, total: int, count: int}>}> $roots */
        $roots = [];

        foreach ($totals as $entry) {
            $categoryId = $entry['id'];
            $rootId = null;
            $memberId = null;

            if ($categoryId !== null && isset($map[$categoryId])) {
                $chain = $this->ancestorChain($categoryId);

                if ($chain !== []) {
                    $rootId = $chain[array_key_last($chain)];
                    $memberId = $categoryId;
                }
            }

            $key = $rootId ?? 'uncat';

            if (! isset($roots[$key])) {
                $roots[$key] = ['id' => $rootId, 'total' => 0, 'count' => 0, 'members' => []];
            }

            $roots[$key]['total'] += $entry['total'];
            $roots[$key]['count'] += $entry['count'];

            if ($memberId !== null) {
                $roots[$key]['members'][] = ['id' => $memberId, 'total' => $entry['total'], 'count' => $entry['count']];
            }
        }

        $sum = 0;
        $max = 0;

        foreach ($roots as $root) {
            $sum += $root['total'];
            $max = max($max, $root['total']);
        }

        uasort($roots, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $buckets = [];
        $index = 0;

        foreach ($roots as $root) {
            $rootId = $root['id'];
            $name = $rootId !== null ? ($map[$rootId]['name'] ?? 'Uncategorised') : 'Uncategorised';
            $color = $this->paletteColor(
                $rootId !== null && isset($map[$rootId]) ? $map[$rootId]['color'] : null,
                $index,
            );

            $children = [];
            $expandable = false;

            foreach ($root['members'] as $member) {
                if ($member['id'] === $rootId) {
                    continue;
                }

                $expandable = true;

                $children[] = [
                    'id' => $member['id'],
                    'path' => $this->pathLabel($member['id'], $map),
                    'total' => $member['total'],
                    'count' => $member['count'],
                    'pct' => $sum > 0 ? round($member['total'] / $sum * 100, 1) : 0.0,
                    'perMonth' => intdiv($member['total'], $months),
                    'bar' => $root['total'] > 0 ? (int) min(100, max(0, round($member['total'] / $root['total'] * 100))) : 0,
                ];
            }

            usort($children, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

            $buckets[] = [
                'id' => $rootId,
                'name' => $name,
                'icon' => $rootId !== null ? $this->resolveIconFromMap($rootId, $map) : null,
                'color' => $color,
                'total' => $root['total'],
                'count' => $root['count'],
                'pct' => $sum > 0 ? round($root['total'] / $sum * 100, 1) : 0.0,
                'perMonth' => intdiv($root['total'], $months),
                'bar' => $max > 0 ? (int) min(100, max(0, round($root['total'] / $max * 100))) : 0,
                'expandable' => $expandable,
                'children' => $children,
            ];

            $index++;
        }

        return $this->bucketsCache[$directionValue] = ['buckets' => $buckets, 'sum' => $sum, 'max' => $max];
    }

    /**
     * @param  list<array{id: int|null, name: string, icon: string|null, color: string, total: int, count: int, pct: float, perMonth: int, bar: int, expandable: bool, children: list<array{id: int, path: string, total: int, count: int, pct: float, perMonth: int, bar: int}>}>  $buckets
     * @return list<array{x: string, y: int, color: string}>
     */
    private function treemapNodes(array $buckets): array
    {
        $nodes = [];

        foreach ($buckets as $bucket) {
            if ($bucket['total'] <= 0) {
                continue;
            }

            $nodes[] = ['x' => $bucket['name'], 'y' => $bucket['total'], 'color' => $bucket['color']];
        }

        return $nodes;
    }

    /**
     * @param  list<array{id: int|null, name: string, icon: string|null, color: string, total: int, count: int, pct: float, perMonth: int, bar: int, expandable: bool, children: list<array{id: int, path: string, total: int, count: int, pct: float, perMonth: int, bar: int}>}>  $buckets
     * @return list<array{id: int|null, name: string, icon: string|null, color: string, total: int, count: int, pct: float, perMonth: int, bar: int, expandable: bool, children: list<array{id: int, path: string, total: int, count: int, pct: float, perMonth: int, bar: int}>}>
     */
    private function limitBuckets(array $buckets): array
    {
        return $this->topOnly ? array_slice($buckets, 0, self::TOP_LIMIT) : $buckets;
    }

    /**
     * @param  array<int, array{id: int, name: string, parent_id: int|null, icon: string|null, color: string|null}>  $map
     */
    private function pathLabel(int $categoryId, array $map): string
    {
        $chain = $this->ancestorChain($categoryId);

        if ($chain === []) {
            return $map[$categoryId]['name'] ?? 'Uncategorised';
        }

        $names = [];

        foreach (array_reverse($chain) as $id) {
            $names[] = $map[$id]['name'];
        }

        return implode(' / ', $names);
    }

    private function normaliseDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @return list<int>
     */
    private function ancestorChain(int $categoryId): array
    {
        $map = $this->categoryMap();
        $chain = [];
        $visited = [];
        $currentId = $categoryId;

        while ($currentId !== null && isset($map[$currentId])) {
            if (isset($visited[$currentId])) {
                return [];
            }

            $visited[$currentId] = true;
            $chain[] = $currentId;
            $currentId = $map[$currentId]['parent_id'];
        }

        return $chain;
    }

    /**
     * @param  array<int, array{id: int, name: string, parent_id: int|null, icon: string|null, color: string|null}>  $map
     */
    private function resolveIconFromMap(?int $categoryId, array $map): ?string
    {
        $visited = [];

        while ($categoryId !== null && isset($map[$categoryId]) && ! isset($visited[$categoryId])) {
            $visited[$categoryId] = true;

            if ($map[$categoryId]['icon'] !== null) {
                return $map[$categoryId]['icon'];
            }

            $categoryId = $map[$categoryId]['parent_id'];
        }

        return null;
    }

    private function paletteColor(?string $color, int $index): string
    {
        if ($color !== null && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) === 1) {
            return $color;
        }

        return self::FALLBACK_COLORS[$index % count(self::FALLBACK_COLORS)];
    }

    /**
     * @return array<int, array{id: int, name: string, parent_id: int|null, icon: string|null, color: string|null}>
     */
    private function categoryMap(): array
    {
        if ($this->categoryMap !== null) {
            return $this->categoryMap;
        }

        $map = [];

        foreach (Category::query()->get(['id', 'name', 'parent_id', 'icon', 'color']) as $category) {
            $map[$category->id] = [
                'id' => $category->id,
                'name' => $category->name,
                'parent_id' => $category->parent_id,
                'icon' => $category->icon,
                'color' => $category->color,
            ];
        }

        return $this->categoryMap = $map;
    }

    private function aggregator(): ReportAggregator
    {
        return app(ReportAggregator::class);
    }

    private function user(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new RuntimeException('The reports page requires an authenticated user.');
        }

        return $user;
    }
}
