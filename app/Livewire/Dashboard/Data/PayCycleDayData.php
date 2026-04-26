<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Data;

final readonly class PayCycleDayData
{
    /**
     * @param  list<PayCyclePip>  $pips  Full unsliced pip list for the day; the grid view slices for display, the detail panel uses the complete list.
     */
    public function __construct(
        public string $iso,
        public int $day,
        public string $dayName,
        public bool $isToday,
        public bool $isCycleStart,
        public bool $isCycleEnd,
        public bool $isPast,
        public array $pips,
        public int $hiddenCount,
        public int $netCents,
        public int $incomeCents,
        public int $postedCents,
        public int $plannedCents,
    ) {}
}
