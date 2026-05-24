<?php

declare(strict_types=1);

namespace App\Livewire\Data;

use App\Livewire\Dashboard\Data\PayCyclePip;

final readonly class CalendarDayData
{
    /**
     * @param  list<PayCyclePip>  $pips  Full unsliced pip list for the day; the grid view slices for display, the detail panel uses the complete list.
     */
    public function __construct(
        public string $iso,
        public int $day,
        public string $dayName,
        public int $isoWeekday,
        public bool $isToday,
        public bool $isPast,
        public bool $isCurrentMonth,
        public bool $isPastPayday,
        public bool $isNextPayday,
        public bool $isInActiveCycle,
        public array $pips,
        public int $hiddenCount,
        public int $netCents,
        public int $incomeCents,
        public int $postedCents,
        public int $plannedCents,
    ) {}
}
