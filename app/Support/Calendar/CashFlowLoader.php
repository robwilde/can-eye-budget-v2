<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Enums\TransactionDirection;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\User;
use App\Services\Reports\CategoryGroupMap;
use App\Services\Reports\ReportAggregator;
use Carbon\CarbonImmutable;

/**
 * Nets a user's month by category and shapes it into hub-graph parents. A parent is a category group;
 * its children are the group's categories, with deeper levels rolled into the category. Categories with
 * no group fall under "Ungrouped" and posts with no category sit under "Uncategorised".
 */
final readonly class CashFlowLoader
{
    private const string UNGROUPED_COLOR = '#8C9492';

    public function __construct(
        private ReportAggregator $aggregator,
        private CashFlowGraphBuilder $builder,
    ) {}

    /**
     * Transfers with one tracked side are included as Savings & Investments or Transfer spend and income.
     */
    public function load(User $user, CarbonImmutable $monthStart): CashFlow
    {
        return $this->builder->build($this->parents($user, $monthStart, includeTransfers: true));
    }

    /**
     * @return list<FlowParent>
     */
    public function parents(User $user, CarbonImmutable $monthStart, bool $includeTransfers): array
    {
        $atoms = $this->aggregator->atoms($user, 'actual', $monthStart, $monthStart->endOfMonth(), $includeTransfers);

        if ($atoms === []) {
            return [];
        }

        $map = CategoryGroupMap::load();
        $groups = CategoryGroup::query()->get()->keyBy('id');
        $colours = Category::query()->whereNotNull('color')->pluck('color', 'id');

        /** @var array<string, array{name: string, color: string, groupId: int|null, own: int, children: array<string, array{name: string, color: string, net: int}>}> $tree */
        $tree = [];

        foreach ($atoms as $atom) {
            $net = $atom['direction'] === TransactionDirection::Debit->value ? $atom['total'] : -$atom['total'];
            $categoryId = $atom['category_id'];

            if ($categoryId === null) {
                $tree[FlowParent::UNCATEGORISED] ??= ['name' => __('Uncategorised'), 'color' => self::UNGROUPED_COLOR, 'groupId' => null, 'own' => 0, 'children' => []];
                $tree[FlowParent::UNCATEGORISED]['own'] += $net;

                continue;
            }

            $anchorId = $map->anchorId($categoryId);
            $groupId = $anchorId === null ? null : $map->groupId($anchorId);
            $group = $groupId === null ? null : $groups->get($groupId);
            $childId = $group === null ? $categoryId : $anchorId;

            $parentKey = $group === null ? 'ungrouped' : 'g'.$group->id;
            $parentColor = $group->color ?? self::UNGROUPED_COLOR;

            $tree[$parentKey] ??= ['name' => $group->name ?? __('Ungrouped'), 'color' => $parentColor, 'groupId' => $group?->id, 'own' => 0, 'children' => []];

            $childKey = 'c'.$childId;
            $tree[$parentKey]['children'][$childKey] ??= [
                'name' => $map->name((int) $childId),
                'color' => $this->safeColour($colours->get($childId), $parentColor),
                'net' => 0,
            ];
            $tree[$parentKey]['children'][$childKey]['net'] += $net;
        }

        $parents = [];

        foreach ($tree as $key => $parent) {
            $children = [];

            foreach ($parent['children'] as $childKey => $child) {
                $children[] = new FlowChild($childKey, $child['name'], $child['color'], $child['net']);
            }

            $parents[] = new FlowParent($key, $parent['name'], $parent['color'], $parent['groupId'], $parent['own'], $children);
        }

        return $parents;
    }

    private function safeColour(mixed $colour, string $fallback): string
    {
        return is_string($colour) && preg_match(CategoryGroup::COLOR_PATTERN, $colour) === 1 ? $colour : $fallback;
    }
}
