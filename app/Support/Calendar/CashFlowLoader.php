<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Enums\CashFlowGrouping;
use App\Enums\TransactionDirection;
use App\Models\Account;
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

    private const array ACCOUNT_COLORS = ['#2F7D6E', '#B5651D', '#4A6FA5', '#8E5A9B', '#A3485A', '#6B8E23'];

    public function __construct(
        private ReportAggregator $aggregator,
        private CashFlowGraphBuilder $builder,
    ) {}

    /**
     * The range runs from $start to $end, or over the whole month of $start when no end is given.
     * Transfers with one tracked side are included as Savings & Investments or Transfer spend and income.
     */
    public function load(User $user, CarbonImmutable $start, ?CarbonImmutable $end = null, CashFlowGrouping $grouping = CashFlowGrouping::Group): CashFlow
    {
        return $this->builder->build($this->parents($user, $start, includeTransfers: true, end: $end, grouping: $grouping));
    }

    /**
     * @return list<FlowParent>
     */
    public function parents(User $user, CarbonImmutable $start, bool $includeTransfers, ?CarbonImmutable $end = null, CashFlowGrouping $grouping = CashFlowGrouping::Group): array
    {
        $end ??= $start->endOfMonth();

        if ($grouping === CashFlowGrouping::Account) {
            return $this->accountParents($user, $this->aggregator->accountAtoms($user, $start, $end, $includeTransfers));
        }

        return $this->groupParents($user, $this->aggregator->atoms($user, 'actual', $start, $end, $includeTransfers));
    }

    private static function accountColour(int $accountId): string
    {
        return self::ACCOUNT_COLORS[$accountId % count(self::ACCOUNT_COLORS)];
    }

    /**
     * One parent per account that has counted flow, its children the categories booked on it and posts
     * with no category as the parent's own share.
     *
     * @param  list<array{account_id: int, direction: string, category_id: int|null, total: int}>  $atoms
     * @return list<FlowParent>
     */
    private function accountParents(User $user, array $atoms): array
    {
        if ($atoms === []) {
            return [];
        }

        $map = CategoryGroupMap::load($user->id);
        $accounts = Account::query()->where('user_id', $user->id)->orderBy('id')->pluck('name', 'id');
        $colours = Category::query()->where('user_id', $user->id)->whereNotNull('color')->pluck('color', 'id');

        /** @var array<int, array{own: int, children: array<string, array{name: string, color: string, net: int}>}> $tree */
        $tree = [];

        foreach ($atoms as $atom) {
            $net = $atom['direction'] === TransactionDirection::Debit->value ? $atom['total'] : -$atom['total'];
            $accountId = $atom['account_id'];
            $tree[$accountId] ??= ['own' => 0, 'children' => []];

            if ($atom['category_id'] === null) {
                $tree[$accountId]['own'] += $net;

                continue;
            }

            $childKey = 'c'.$atom['category_id'];
            $tree[$accountId]['children'][$childKey] ??= [
                'name' => $map->name($atom['category_id']),
                'color' => $this->safeColour($colours->get($atom['category_id']), self::accountColour($accountId)),
                'net' => 0,
            ];
            $tree[$accountId]['children'][$childKey]['net'] += $net;
        }

        $parents = [];

        foreach ($tree as $accountId => $account) {
            $children = [];

            foreach ($account['children'] as $childKey => $child) {
                $children[] = new FlowChild($childKey, $child['name'], $child['color'], $child['net']);
            }

            $parents[] = new FlowParent('a'.$accountId, (string) ($accounts->get($accountId) ?? __('Account')), self::accountColour($accountId), null, $account['own'], $children);
        }

        return $parents;
    }

    /**
     * @param  list<array{ym: string, direction: string, category_id: int|null, total: int, count: int}>  $atoms
     * @return list<FlowParent>
     */
    private function groupParents(User $user, array $atoms): array
    {
        if ($atoms === []) {
            return [];
        }

        $map = CategoryGroupMap::load($user->id);
        $groups = CategoryGroup::query()->where('user_id', $user->id)->get()->keyBy('id');
        $colours = Category::query()->where('user_id', $user->id)->whereNotNull('color')->pluck('color', 'id');

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
