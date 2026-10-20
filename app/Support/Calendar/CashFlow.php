<?php

declare(strict_types=1);

namespace App\Support\Calendar;

/**
 * Month cash flow, every figure net by category. A graph is {nodes, links}: node values are cents and
 * link endpoints index into nodes. `overview` shows parents only; `drilldowns` is keyed by a drillable
 * overview node id and holds that parent and its children.
 *
 * @phpstan-type GraphNode array{id: string, name: string, kind: string, value: int, color: string, drillable: bool}
 * @phpstan-type GraphLink array{source: int, target: int, value: int}
 * @phpstan-type Graph array{nodes: list<GraphNode>, links: list<GraphLink>}
 * @phpstan-type Outflow array{id: string, name: string, color: string, amount: int, percentage: float, groupId: int|null, uncategorised: bool}
 */
final readonly class CashFlow
{
    /**
     * @param  Graph  $overview
     * @param  array<string, Graph>  $drilldowns
     * @param  list<Outflow>  $outflows
     */
    public function __construct(
        public int $income,
        public int $spending,
        public array $overview,
        public array $drilldowns,
        public array $outflows,
    ) {}

    public function net(): int
    {
        return $this->income - $this->spending;
    }

    public function isEmpty(): bool
    {
        return $this->overview['nodes'] === [];
    }
}
