<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\TransactionDirection;
use App\Models\CategoryGroup;
use App\Models\User;
use Carbon\CarbonInterface;

final readonly class CategoryGroupReport
{
    public function __construct(private ReportAggregator $aggregator) {}

    /**
     * Totals per group, then per category in the group, then per child of that
     * category. Anything deeper rolls up into the child, so each atom is
     * counted once. Categories with no group are left out; atoms with no
     * category come back as one trailing "Uncategorised" entry with a null id.
     *
     * @return list<array{
     *     id: int|null, name: string, color: string|null, icon: string|null, total: int, count: int,
     *     categories: list<array{
     *         id: int, name: string, icon: string|null, total: int, count: int,
     *         children: list<array{id: int, name: string, icon: string|null, total: int, count: int}>
     *     }>
     * }>
     */
    public function build(User $user, string $mode, ?CarbonInterface $start, ?CarbonInterface $end, TransactionDirection $direction): array
    {
        $map = CategoryGroupMap::load($user->id);
        $groups = CategoryGroup::query()->where('user_id', $user->id)->orderBy('sort_order')->orderBy('name')->get()->keyBy('id');

        /** @var array<int, array{total: int, count: int, categories: array<int, array{total: int, count: int, children: array<int, array{total: int, count: int}>}>}> $tree */
        $tree = [];
        $uncategorised = ['total' => 0, 'count' => 0];

        foreach ($this->aggregator->atoms($user, $mode, $start, $end) as $atom) {
            if ($atom['direction'] !== $direction->value) {
                continue;
            }

            if ($atom['category_id'] === null) {
                $uncategorised['total'] += $atom['total'];
                $uncategorised['count'] += $atom['count'];

                continue;
            }

            $anchorId = $map->anchorId($atom['category_id']);
            $groupId = $anchorId === null ? null : $map->groupId($anchorId);

            if ($anchorId === null || $groupId === null || ! $groups->has($groupId)) {
                continue;
            }

            $tree[$groupId] ??= ['total' => 0, 'count' => 0, 'categories' => []];
            $tree[$groupId]['total'] += $atom['total'];
            $tree[$groupId]['count'] += $atom['count'];

            $tree[$groupId]['categories'][$anchorId] ??= ['total' => 0, 'count' => 0, 'children' => []];
            $tree[$groupId]['categories'][$anchorId]['total'] += $atom['total'];
            $tree[$groupId]['categories'][$anchorId]['count'] += $atom['count'];

            $childId = $map->childUnder($anchorId, $atom['category_id']);

            if ($childId !== null) {
                $tree[$groupId]['categories'][$anchorId]['children'][$childId] ??= ['total' => 0, 'count' => 0];
                $tree[$groupId]['categories'][$anchorId]['children'][$childId]['total'] += $atom['total'];
                $tree[$groupId]['categories'][$anchorId]['children'][$childId]['count'] += $atom['count'];
            }
        }

        $rows = [];

        foreach ($tree as $groupId => $node) {
            $group = $groups[$groupId];

            $rows[] = [
                'id' => $groupId,
                'name' => $group->name,
                'color' => $group->color,
                'icon' => $group->icon,
                'total' => $node['total'],
                'count' => $node['count'],
                'categories' => $this->categoryRows($map, $node['categories']),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        if ($uncategorised['count'] > 0) {
            $rows[] = [
                'id' => null,
                'name' => __('Uncategorised'),
                'color' => null,
                'icon' => null,
                'total' => $uncategorised['total'],
                'count' => $uncategorised['count'],
                'categories' => [],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array{total: int, count: int, children?: array<int, array{total: int, count: int}>}>  $categories
     * @return list<array{id: int, name: string, icon: string|null, total: int, count: int, children: list<array{id: int, name: string, icon: string|null, total: int, count: int}>}>
     */
    private function categoryRows(CategoryGroupMap $map, array $categories): array
    {
        $rows = [];

        foreach ($categories as $id => $node) {
            $rows[] = [
                'id' => $id,
                'name' => $map->name($id),
                'icon' => $map->icon($id),
                'total' => $node['total'],
                'count' => $node['count'],
                'children' => $this->leafRows($map, $node['children'] ?? []),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $rows;
    }

    /**
     * @param  array<int, array{total: int, count: int}>  $children
     * @return list<array{id: int, name: string, icon: string|null, total: int, count: int}>
     */
    private function leafRows(CategoryGroupMap $map, array $children): array
    {
        $rows = [];

        foreach ($children as $id => $node) {
            $rows[] = [
                'id' => $id,
                'name' => $map->name($id),
                'icon' => $map->icon($id),
                'total' => $node['total'],
                'count' => $node['count'],
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $rows;
    }
}
