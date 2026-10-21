<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Support\Calendar\CashFlow;
use App\Support\Calendar\CashFlowGraphBuilder;
use App\Support\Calendar\FlowChild;
use App\Support\Calendar\FlowParent;

/**
 * @param  array<string, int>  $children  child key => net cents (positive is spending)
 */
function cashFlowParent(string $key, int $own, array $children = [], ?int $groupId = null): FlowParent
{
    $flowChildren = [];

    foreach ($children as $childKey => $net) {
        $flowChildren[] = new FlowChild($childKey, ucfirst($childKey), '#123456', $net);
    }

    return new FlowParent($key, ucfirst($key), '#654321', $groupId, $own, $flowChildren);
}

/**
 * @param  list<FlowParent>  $parents
 */
function cashFlowBuild(array $parents): CashFlow
{
    return (new CashFlowGraphBuilder)->build($parents);
}

/**
 * @param  array{nodes: list<array<string, mixed>>, links: list<array{source: int, target: int, value: int}>}  $graph
 * @return array<string, mixed>|null
 */
function cashFlowNode(array $graph, string $id): ?array
{
    foreach ($graph['nodes'] as $node) {
        if ($node['id'] === $id) {
            return $node;
        }
    }

    return null;
}

/**
 * @param  array{nodes: list<array<string, mixed>>, links: list<array{source: int, target: int, value: int}>}  $graph
 * @return list<array{0: string, 1: string, 2: int}>
 */
function cashFlowLinkTriples(array $graph): array
{
    $triples = array_map(
        fn (array $link): array => [$graph['nodes'][$link['source']]['id'], $graph['nodes'][$link['target']]['id'], $link['value']],
        $graph['links'],
    );

    sort($triples);

    return $triples;
}

/**
 * @param  array{nodes: list<array<string, mixed>>, links: list<array{source: int, target: int, value: int}>}  $graph
 */
function expectCashFlowGraphBalanced(array $graph): void
{
    $hub = array_search(CashFlowGraphBuilder::HUB_ID, array_column($graph['nodes'], 'id'), true);

    if ($hub !== false) {
        $incoming = array_sum(array_map(fn (array $l): int => $l['target'] === $hub ? $l['value'] : 0, $graph['links']));
        $outgoing = array_sum(array_map(fn (array $l): int => $l['source'] === $hub ? $l['value'] : 0, $graph['links']));

        expect($incoming)->toBe($outgoing);
    }

    foreach ($graph['nodes'] as $index => $node) {
        $incoming = array_sum(array_map(fn (array $l): int => $l['target'] === $index ? $l['value'] : 0, $graph['links']));
        $outgoing = array_sum(array_map(fn (array $l): int => $l['source'] === $index ? $l['value'] : 0, $graph['links']));

        expect($node['value'])->toBeGreaterThan(0, "{$node['id']} must be positive")
            ->and($node['value'])->toBe(max($incoming, $outgoing), "{$node['id']} must match its links' capacity");
    }

    foreach ($graph['links'] as $link) {
        expect($graph['nodes'])->toHaveKeys([$link['source'], $link['target']]);
    }
}

function expectCashFlowBalanced(CashFlow $flow): void
{
    expectCashFlowGraphBalanced($flow->overview);

    foreach ($flow->drilldowns as $graph) {
        expectCashFlowGraphBalanced($graph);
    }
}

test('opposite-direction children are not double counted in parent totals', function () {
    $flow = cashFlowBuild([cashFlowParent('shopping', 10000, ['rebates' => -3000])]);

    expect($flow->spending)->toBe(10000)
        ->and($flow->income)->toBe(3000)
        ->and($flow->net())->toBe(-7000)
        ->and(cashFlowNode($flow->overview, 'expense_shopping')['value'])->toBe(10000)
        ->and(cashFlowNode($flow->overview, 'income_shopping')['value'])->toBe(3000)
        ->and(cashFlowNode($flow->overview, CashFlowGraphBuilder::DEFICIT_ID)['value'])->toBe(7000)
        ->and(cashFlowNode($flow->overview, CashFlowGraphBuilder::SURPLUS_ID))->toBeNull()
        ->and(cashFlowNode($flow->drilldowns['income_shopping'], 'income_sub_shopping_rebates')['value'])->toBe(3000)
        ->and(cashFlowNode($flow->overview, 'income_shopping')['drillable'])->toBeTrue()
        ->and(cashFlowNode($flow->overview, 'expense_shopping')['drillable'])->toBeFalse()
        ->and($flow->drilldowns)->toHaveKeys(['income_shopping'])
        ->and($flow->drilldowns)->not->toHaveKey('expense_shopping');

    expectCashFlowBalanced($flow);
});

test('a zero-net parent keeps both directions and draws no surplus or deficit', function () {
    $flow = cashFlowBuild([cashFlowParent('shopping', 10000, ['rebates' => -10000])]);

    expect($flow->income)->toBe(10000)
        ->and($flow->spending)->toBe(10000)
        ->and($flow->net())->toBe(0)
        ->and(cashFlowNode($flow->overview, 'expense_shopping')['value'])->toBe(10000)
        ->and(cashFlowNode($flow->overview, 'income_shopping')['value'])->toBe(10000)
        ->and(cashFlowNode($flow->overview, CashFlowGraphBuilder::SURPLUS_ID))->toBeNull()
        ->and(cashFlowNode($flow->overview, CashFlowGraphBuilder::DEFICIT_ID))->toBeNull();

    expectCashFlowBalanced($flow);
});

test('same-direction children share the parent capacity without duplicating totals', function () {
    $flow = cashFlowBuild([cashFlowParent('shopping', 1000, ['clothes' => 2000])]);
    $drill = $flow->drilldowns['expense_shopping'];

    expect($flow->spending)->toBe(3000)
        ->and(cashFlowNode($flow->overview, 'expense_shopping')['value'])->toBe(3000)
        ->and(cashFlowNode($drill, 'expense_shopping')['value'])->toBe(3000)
        ->and(cashFlowNode($drill, 'expense_sub_shopping_clothes')['value'])->toBe(2000)
        ->and(cashFlowNode($drill, 'expense_own_shopping')['value'])->toBe(1000)
        ->and(cashFlowLinkTriples($drill))->toBe([
            ['expense_shopping', 'expense_own_shopping', 1000],
            ['expense_shopping', 'expense_sub_shopping_clothes', 2000],
        ]);

    expectCashFlowBalanced($flow);
});

test('an income drill-down flows from the children into the parent', function () {
    $flow = cashFlowBuild([cashFlowParent('income', -1000, ['salary' => -500000, 'interest' => -2500])]);
    $drill = $flow->drilldowns['income_income'];

    expect(cashFlowLinkTriples($drill))->toBe([
        ['income_own_income', 'income_income', 1000],
        ['income_sub_income_interest', 'income_income', 2500],
        ['income_sub_income_salary', 'income_income', 500000],
    ]);

    expectCashFlowBalanced($flow);
});

test('a spending-only month has an explicit deficit through the hub to its expenses', function () {
    $flow = cashFlowBuild([cashFlowParent('shopping', 16000)]);

    expect($flow->income)->toBe(0)
        ->and($flow->spending)->toBe(16000)
        ->and($flow->net())->toBe(-16000)
        ->and(cashFlowLinkTriples($flow->overview))->toBe([
            [CashFlowGraphBuilder::HUB_ID, 'expense_shopping', 16000],
            [CashFlowGraphBuilder::DEFICIT_ID, CashFlowGraphBuilder::HUB_ID, 16000],
        ]);

    expectCashFlowBalanced($flow);
});

test('a surplus month routes the leftover from the hub to the surplus node', function () {
    $flow = cashFlowBuild([
        cashFlowParent('income', 0, ['salary' => -500000]),
        cashFlowParent('bills', 0, ['rent' => 300000]),
    ]);

    expect($flow->net())->toBe(200000)
        ->and(cashFlowNode($flow->overview, CashFlowGraphBuilder::DEFICIT_ID))->toBeNull()
        ->and(cashFlowLinkTriples($flow->overview))->toBe([
            [CashFlowGraphBuilder::HUB_ID, 'expense_bills', 300000],
            [CashFlowGraphBuilder::HUB_ID, CashFlowGraphBuilder::SURPLUS_ID, 200000],
            ['income_income', CashFlowGraphBuilder::HUB_ID, 500000],
        ])
        ->and(cashFlowNode($flow->overview, CashFlowGraphBuilder::HUB_ID)['value'])->toBe(500000);

    expectCashFlowBalanced($flow);
});

test('an empty month has no nodes, links, drill-downs or outflows and builds deterministically', function (array $parents) {
    $flow = cashFlowBuild($parents);

    expect($flow->isEmpty())->toBeTrue()
        ->and($flow->income)->toBe(0)
        ->and($flow->spending)->toBe(0)
        ->and($flow->overview)->toBe(['nodes' => [], 'links' => []])
        ->and($flow->drilldowns)->toBe([])
        ->and($flow->outflows)->toBe([])
        ->and(cashFlowBuild($parents))->toEqual($flow);
})->with([
    'no parents' => [[]],
    'only zero nets' => fn () => [cashFlowParent('shopping', 0, ['rebates' => 0])],
]);

test('the same parents build the same graph whatever order they arrive in', function () {
    $parents = [
        cashFlowParent('bills', 0, ['rent' => 300000, 'power' => 12000]),
        cashFlowParent('food', 4000, ['groceries' => 50000]),
        cashFlowParent('income', 0, ['salary' => -500000]),
        cashFlowParent(FlowParent::UNCATEGORISED, 7000),
    ];

    expect(cashFlowBuild(array_reverse($parents)))->toEqual(cashFlowBuild($parents));
});

test('the uncategorised parent is never drillable and is flagged in outflows', function () {
    $flow = cashFlowBuild([cashFlowParent(FlowParent::UNCATEGORISED, 7000)]);
    $outflow = collect($flow->outflows)->firstWhere('id', FlowParent::UNCATEGORISED);

    expect(cashFlowNode($flow->overview, 'expense_uncategorised')['value'])->toBe(7000)
        ->and(cashFlowNode($flow->overview, 'expense_uncategorised')['drillable'])->toBeFalse()
        ->and($flow->drilldowns)->toBe([])
        ->and($outflow['uncategorised'])->toBeTrue()
        ->and($outflow['groupId'])->toBeNull();
});

test('every overview and drill-down graph balances', function () {
    $flow = cashFlowBuild([
        cashFlowParent('income', -2000, ['salary' => -500000, 'refunds' => 1500], groupId: 1),
        cashFlowParent('bills', 3000, ['rent' => 300000, 'power' => 12000, 'credit' => -4500], groupId: 2),
        cashFlowParent('food', 0, ['groceries' => 50000, 'eating' => 22000], groupId: 3),
        cashFlowParent('savings', 0, ['spaceship' => 25000, 'withdrawal' => -10000], groupId: 4),
        cashFlowParent(FlowParent::UNCATEGORISED, 7000),
    ]);

    expect($flow->drilldowns)->toHaveKeys(['income_income', 'expense_income', 'expense_bills', 'income_bills', 'expense_food', 'expense_savings', 'income_savings']);

    foreach ($flow->drilldowns as $id => $graph) {
        $root = cashFlowNode($graph, $id);

        expect($root)->not->toBeNull()
            ->and($root['value'])->toBe(cashFlowNode($flow->overview, $id)['value'])
            ->and(cashFlowNode($flow->overview, $id)['drillable'])->toBeTrue();
    }

    expect(cashFlowNode($flow->drilldowns['expense_bills'], 'expense_own_bills')['value'])->toBe(3000)
        ->and(cashFlowNode($flow->drilldowns['income_income'], 'income_own_income')['value'])->toBe(2000)
        ->and(cashFlowNode($flow->drilldowns['expense_food'], 'expense_own_food'))->toBeNull();

    expectCashFlowBalanced($flow);
});

test('outflows total the spending, are ranked largest first and take their percentages from it', function () {
    $flow = cashFlowBuild([
        cashFlowParent('income', 0, ['salary' => -500000], groupId: 1),
        cashFlowParent('bills', 0, ['rent' => 300000, 'credit' => -4500], groupId: 2),
        cashFlowParent('food', 0, ['groceries' => 50000, 'eating' => 22000], groupId: 3),
        cashFlowParent('savings', 0, ['spaceship' => 25000], groupId: 4),
        cashFlowParent(FlowParent::UNCATEGORISED, 7000),
    ]);
    $amounts = array_column($flow->outflows, 'amount');
    $sorted = $amounts;
    rsort($sorted);

    expect(array_sum($amounts))->toBe($flow->spending)
        ->and($flow->spending)->toBe(404000)
        ->and($amounts)->toBe($sorted)
        ->and(array_column($flow->outflows, 'id'))->toBe(['bills', 'food', 'savings', FlowParent::UNCATEGORISED])
        ->and(array_column($flow->outflows, 'groupId'))->toBe([2, 3, 4, null]);

    foreach ($flow->outflows as $outflow) {
        expect($outflow['percentage'])->toBe(round($outflow['amount'] / $flow->spending * 100, 1));
    }
});

test('outflows rank by spending even when a parent carries more income than spending', function () {
    $flow = cashFlowBuild([
        cashFlowParent('transfer', 0, ['in' => -300000, 'out' => 10000], groupId: 1),
        cashFlowParent('food', 0, ['groceries' => 50000], groupId: 2),
    ]);

    expect(array_column($flow->outflows, 'id'))->toBe(['food', 'transfer'])
        ->and(array_column($flow->outflows, 'amount'))->toBe([50000, 10000]);
});
