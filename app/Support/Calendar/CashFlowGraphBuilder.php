<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Enums\Side;

/**
 * Hub graph: income parents -> Cash flow -> expense parents -> Surplus or Deficit. Ported from
 * sure-finance IncomeStatement::Sankey: refunds are already netted inside each category, a parent
 * may carry income and expense nodes at once, and a drill-down balances by adding the parent's own
 * share as a node of its own, so no graph ever counts an amount twice.
 *
 * @phpstan-import-type Graph from CashFlow
 * @phpstan-import-type Outflow from CashFlow
 */
final class CashFlowGraphBuilder
{
    public const string HUB_ID = 'cash_flow_node';

    public const string SURPLUS_ID = 'surplus_node';

    public const string DEFICIT_ID = 'deficit_node';

    private const string HUB_COLOR = '#5E6664';

    private const string SURPLUS_COLOR = '#18923A';

    private const string DEFICIT_COLOR = '#E5484D';

    /**
     * @param  list<FlowParent>  $parents
     */
    public function build(array $parents): CashFlow
    {
        $income = 0;
        $spending = 0;

        foreach ($parents as $parent) {
            $income += $parent->sideTotal(Side::Income);
            $spending += $parent->sideTotal(Side::Expense);
        }

        $capacity = max($income, $spending);

        if ($capacity === 0) {
            return new CashFlow(0, 0, ['nodes' => [], 'links' => []], [], []);
        }

        usort($parents, static fn (FlowParent $a, FlowParent $b): int => [
            max($b->sideTotal(Side::Income), $b->sideTotal(Side::Expense)),
            $a->key,
        ] <=> [
            max($a->sideTotal(Side::Income), $a->sideTotal(Side::Expense)),
            $b->key,
        ]);

        $nodes = [];
        $links = [];
        $drilldowns = [];
        $outflows = [];

        $hub = $this->addNode($nodes, self::HUB_ID, 'Cash flow', 'cash_flow', $capacity, self::HUB_COLOR, false);

        foreach ($parents as $parent) {
            foreach ([Side::Income, Side::Expense] as $side) {
                $amount = $parent->sideTotal($side);

                if ($amount === 0) {
                    continue;
                }

                $id = $side->value.'_'.$parent->key;
                $drill = $this->drilldown($parent, $side, $id, $amount);
                $index = $this->addNode($nodes, $id, $parent->name, $side->value, $amount, $parent->color, $drill !== null);

                $links[] = $side === Side::Income
                    ? ['source' => $index, 'target' => $hub, 'value' => $amount]
                    : ['source' => $hub, 'target' => $index, 'value' => $amount];

                if ($drill !== null) {
                    $drilldowns[$id] = $drill;
                }

                if ($side === Side::Expense) {
                    $outflows[] = [
                        'id' => $parent->key,
                        'name' => $parent->name,
                        'color' => $parent->color,
                        'amount' => $amount,
                        'percentage' => round($amount / $spending * 100, 1),
                        'groupId' => $parent->groupId,
                        'uncategorised' => $parent->key === FlowParent::UNCATEGORISED,
                    ];
                }
            }
        }

        $net = $income - $spending;

        if ($net !== 0) {
            $surplus = $net > 0;
            $index = $this->addNode(
                $nodes,
                $surplus ? self::SURPLUS_ID : self::DEFICIT_ID,
                $surplus ? 'Surplus' : 'Deficit',
                $surplus ? 'surplus' : 'deficit',
                abs($net),
                $surplus ? self::SURPLUS_COLOR : self::DEFICIT_COLOR,
                false,
            );

            $links[] = $surplus
                ? ['source' => $hub, 'target' => $index, 'value' => $net]
                : ['source' => $index, 'target' => $hub, 'value' => abs($net)];
        }

        return new CashFlow($income, $spending, ['nodes' => $nodes, 'links' => $links], $drilldowns, $outflows);
    }

    /**
     * @return Graph|null
     */
    private function drilldown(FlowParent $parent, Side $side, string $rootId, int $total): ?array
    {
        $children = [];

        foreach ($parent->children as $child) {
            $amount = $side->amount($child->net);

            if ($amount > 0) {
                $children[] = [
                    'id' => $side->value.'_sub_'.$parent->key.'_'.$child->key,
                    'name' => $child->name,
                    'color' => $child->color,
                    'amount' => $amount,
                ];
            }
        }

        if ($children === []) {
            return null;
        }

        $own = $side->amount($parent->own);

        if ($own > 0) {
            $children[] = [
                'id' => $side->value.'_own_'.$parent->key,
                'name' => $parent->name.' (other)',
                'color' => $parent->color,
                'amount' => $own,
            ];
        }

        usort($children, static fn (array $a, array $b): int => [$b['amount'], $a['id']] <=> [$a['amount'], $b['id']]);

        $nodes = [];
        $links = [];
        $root = $this->addNode($nodes, $rootId, $parent->name, $side->value, $total, $parent->color, false);

        foreach ($children as $child) {
            $index = $this->addNode($nodes, $child['id'], $child['name'], $side->value, $child['amount'], $child['color'], false);

            $links[] = $side === Side::Income
                ? ['source' => $index, 'target' => $root, 'value' => $child['amount']]
                : ['source' => $root, 'target' => $index, 'value' => $child['amount']];
        }

        return ['nodes' => $nodes, 'links' => $links];
    }

    /**
     * @param  list<array{id: string, name: string, kind: string, value: int, color: string, drillable: bool}>  $nodes
     */
    private function addNode(array &$nodes, string $id, string $name, string $kind, int $value, string $color, bool $drillable): int
    {
        $nodes[] = ['id' => $id, 'name' => $name, 'kind' => $kind, 'value' => $value, 'color' => $color, 'drillable' => $drillable];

        return array_key_last($nodes);
    }
}
