<?php

declare(strict_types=1);

namespace App\Support\Calendar;

final readonly class FlowChild
{
    /**
     * @param  int  $net  Net by category in cents: positive is spending, negative is income.
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $color,
        public int $net,
    ) {}
}
