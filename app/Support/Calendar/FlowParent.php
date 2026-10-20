<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Enums\Side;

final readonly class FlowParent
{
    public const string UNCATEGORISED = 'uncategorised';

    /**
     * @param  int  $own  Net cents booked on the parent itself rather than a child: positive is spending.
     * @param  list<FlowChild>  $children
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $color,
        public ?int $groupId,
        public int $own,
        public array $children,
    ) {}

    public function sideTotal(Side $side): int
    {
        $total = $side->amount($this->own);

        foreach ($this->children as $child) {
            $total += $side->amount($child->net);
        }

        return $total;
    }
}
