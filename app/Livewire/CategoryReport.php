<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Enums\TransactionDirection;
use App\Enums\TransactionPeriod;
use App\Models\Category;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

final class CategoryReport extends Component
{
    private const array VALID_DIRECTIONS = ['outgoing', 'incoming'];

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
    public string $direction = 'outgoing';

    #[Url]
    public ?int $parent = null;

    /** @var array<int, array{id: int, name: string, parent_id: int|null, icon: string|null, color: string|null}>|null */
    private ?array $categoryMap = null;

    public function mount(): void
    {
        if (! TransactionPeriod::tryFrom($this->period)) {
            $this->period = 'this-month';
        }

        if (! in_array($this->direction, self::VALID_DIRECTIONS, true)) {
            $this->direction = 'outgoing';
        }

        if ($this->parent !== null) {
            $map = $this->categoryMap();

            if (! isset($map[$this->parent])) {
                $this->parent = null;
            }
        }
    }

    public function updatedPeriod(): void
    {
        if (! TransactionPeriod::tryFrom($this->period)) {
            $this->period = 'this-month';
        }
    }

    public function updatedDirection(): void
    {
        if (! in_array($this->direction, self::VALID_DIRECTIONS, true)) {
            $this->direction = 'outgoing';
        }
    }

    public function updatedFrom(): void
    {
        $this->from = $this->normaliseDate($this->from);
    }

    public function updatedTo(): void
    {
        $this->to = $this->normaliseDate($this->to);
    }

    public function drillInto(int $categoryId): void
    {
        $map = $this->categoryMap();

        if (isset($map[$categoryId])) {
            $this->parent = $categoryId;
        }
    }

    public function drillUp(): void
    {
        if ($this->parent === null) {
            return;
        }

        $map = $this->categoryMap();

        if (! isset($map[$this->parent])) {
            $this->parent = null;

            return;
        }

        $this->parent = $map[$this->parent]['parent_id'];
    }

    /**
     * @return array<int, array{id: int, name: string, parent_id: int|null, icon: string|null, color: string|null}>
     */
    #[Computed]
    public function categories(): array
    {
        return $this->categoryMap();
    }

    /**
     * @return array{buckets: list<array{id: int|null, name: string, icon: string|null, color: string, total: int, count: int, drillable: bool}>, max: int, sum: int}
     */
    #[Computed]
    public function report(): array
    {
        $map = $this->categoryMap();
        $periodEnum = TransactionPeriod::tryFrom($this->period) ?? TransactionPeriod::ThisMonth;
        $dates = $periodEnum->dateRange(auth()->user(), $this->from, $this->to);
        $directionEnum = $this->direction === 'incoming'
            ? TransactionDirection::Credit
            : TransactionDirection::Debit;

        $rows = Transaction::query()
            ->where('user_id', auth()->id())
            ->current()
            ->excludingTransfers()
            ->where('direction', $directionEnum)
            ->when($dates['start'], fn ($q, $s) => $q->where('post_date', '>=', $s))
            ->when($dates['end'], fn ($q, $e) => $q->where('post_date', '<=', $e))
            ->selectRaw('category_id, SUM(ABS(amount)) as total, COUNT(*) as tx_count')
            ->groupBy('category_id')
            ->get();

        /** @var array<string, array{id: int|null, name: string, special: bool, total: int, count: int, deeper: bool}> $buckets */
        $buckets = [];

        foreach ($rows as $row) {
            $categoryId = $row->category_id === null ? null : (int) $row->category_id;
            $bucket = $this->resolveBucket($categoryId, $map);

            if ($bucket === null) {
                continue;
            }

            $key = $bucket['key'];

            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'id' => $bucket['id'],
                    'name' => $bucket['name'],
                    'special' => $bucket['special'],
                    'total' => 0,
                    'count' => 0,
                    'deeper' => false,
                ];
            }

            $buckets[$key]['total'] += (int) $row->getAttribute('total');
            $buckets[$key]['count'] += (int) $row->getAttribute('tx_count');
            $buckets[$key]['deeper'] = $buckets[$key]['deeper'] || $bucket['deeper'];
        }

        uasort($buckets, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $sum = 0;
        $max = 0;
        $index = 0;
        $result = [];

        foreach ($buckets as $bucket) {
            $sum += $bucket['total'];
            $max = max($max, $bucket['total']);

            $color = $this->paletteColor(
                $bucket['id'] !== null && isset($map[$bucket['id']]) ? $map[$bucket['id']]['color'] : null,
                $index,
            );

            $result[] = [
                'id' => $bucket['id'],
                'name' => $bucket['name'],
                'icon' => $bucket['special'] ? null : $this->resolveIconFromMap($bucket['id'], $map),
                'color' => $color,
                'total' => $bucket['total'],
                'count' => $bucket['count'],
                'drillable' => $bucket['deeper'],
            ];

            $index++;
        }

        return ['buckets' => $result, 'max' => $max, 'sum' => $sum];
    }

    /**
     * @return array{in: int, out: int, net: int}
     */
    #[Computed]
    public function summary(): array
    {
        $periodEnum = TransactionPeriod::tryFrom($this->period) ?? TransactionPeriod::ThisMonth;
        $dates = $periodEnum->dateRange(auth()->user(), $this->from, $this->to);

        $rows = Transaction::query()
            ->where('user_id', auth()->id())
            ->current()
            ->excludingTransfers()
            ->when($dates['start'], fn ($q, $s) => $q->where('post_date', '>=', $s))
            ->when($dates['end'], fn ($q, $e) => $q->where('post_date', '<=', $e))
            ->selectRaw('direction, SUM(ABS(amount)) as total')
            ->groupBy('direction')
            ->get();

        $in = 0;
        $out = 0;

        foreach ($rows as $row) {
            $total = (int) $row->getAttribute('total');

            if ($row->direction === TransactionDirection::Credit) {
                $in = $total;
            } else {
                $out = $total;
            }
        }

        return ['in' => $in, 'out' => $out, 'net' => $in - $out];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    #[Computed]
    public function breadcrumb(): array
    {
        if ($this->parent === null) {
            return [];
        }

        $map = $this->categoryMap();
        $trail = [];

        foreach (array_reverse($this->ancestorChain($this->parent)) as $id) {
            $trail[] = ['id' => $id, 'name' => $map[$id]['name']];
        }

        return $trail;
    }

    public function render(): View
    {
        $periodEnum = TransactionPeriod::tryFrom($this->period) ?? TransactionPeriod::ThisMonth;

        return view('livewire.category-report', [
            'formatMoney' => MoneyCast::format(...),
            'periodLabel' => $periodEnum->label(),
            'hasPayCycle' => auth()->user()->hasPayCycleConfigured(),
            'showCustomRange' => $periodEnum === TransactionPeriod::Custom,
        ]);
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
     * @param  array<int, array{id: int, name: string, parent_id: int|null, icon: string|null, color: string|null}>  $map
     * @return array{key: string, id: int|null, name: string, deeper: bool, special: bool}|null
     */
    private function resolveBucket(?int $categoryId, array $map): ?array
    {
        if ($categoryId === null || ! isset($map[$categoryId])) {
            if ($this->parent !== null) {
                return null;
            }

            return ['key' => 'uncategorised', 'id' => null, 'name' => 'Uncategorised', 'deeper' => false, 'special' => true];
        }

        $chain = $this->ancestorChain($categoryId);

        if ($chain === []) {
            if ($this->parent !== null) {
                return null;
            }

            return ['key' => 'uncategorised', 'id' => null, 'name' => 'Uncategorised', 'deeper' => false, 'special' => true];
        }

        if ($this->parent === null) {
            $rootId = $chain[array_key_last($chain)];

            return [
                'key' => (string) $rootId,
                'id' => $rootId,
                'name' => $map[$rootId]['name'],
                'deeper' => $categoryId !== $rootId,
                'special' => false,
            ];
        }

        if ($categoryId === $this->parent) {
            return ['key' => 'self', 'id' => $this->parent, 'name' => 'General', 'deeper' => false, 'special' => true];
        }

        $parentIndex = array_search($this->parent, $chain, true);

        if ($parentIndex === false || $parentIndex === 0) {
            return null;
        }

        $childId = $chain[$parentIndex - 1];

        return [
            'key' => (string) $childId,
            'id' => $childId,
            'name' => $map[$childId]['name'],
            'deeper' => $categoryId !== $childId,
            'special' => false,
        ];
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
}
