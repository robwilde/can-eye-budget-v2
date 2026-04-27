<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Casts\MoneyCast;
use App\Enums\PayFrequency;
use App\Livewire\Dashboard\Data\PayCycleDayData;
use App\Livewire\Dashboard\Data\PayCyclePip;
use App\Support\Calendar\DayActivity;
use App\Support\Calendar\DayActivityLoader;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

final class PayCycleCalendar extends Component
{
    public const int MAX_PIPS_PER_DAY = 3;

    public int $cycleOffset = 0;

    public ?string $selectedDate = null;

    public function previousCycle(): void
    {
        $this->cycleOffset--;
        $this->bustCache();
    }

    public function nextCycle(): void
    {
        $this->cycleOffset++;
        $this->bustCache();
    }

    public function goToCurrentCycle(): void
    {
        $this->cycleOffset = 0;
        $this->selectedDate = CarbonImmutable::today()->format('Y-m-d');
        $this->bustCache();
    }

    public function selectDay(string $iso): void
    {
        $parsed = CarbonImmutable::createFromFormat('Y-m-d', $iso);

        $this->selectedDate = $parsed instanceof CarbonImmutable
            ? $parsed->format('Y-m-d')
            : null;

        unset($this->selectedDay); // @phpstan-ignore property.notFound
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    #[Computed]
    public function bounds(): ?array
    {
        $user = auth()->user();

        if ($user === null || ! $user->hasPayCycleConfigured()) {
            return null;
        }

        $base = $user->currentPayCycleBounds();

        if ($base === null) {
            return null;
        }

        if ($this->cycleOffset === 0) {
            return $base;
        }

        $frequency = $user->pay_frequency;

        return match ($frequency) {
            PayFrequency::Weekly => [
                'start' => $base['start']->addDays(7 * $this->cycleOffset),
                'end' => $base['end']->addDays(7 * $this->cycleOffset),
            ],
            PayFrequency::Fortnightly => [
                'start' => $base['start']->addDays(14 * $this->cycleOffset),
                'end' => $base['end']->addDays(14 * $this->cycleOffset),
            ],
            PayFrequency::Monthly => [
                'start' => $base['start']->addMonthsNoOverflow($this->cycleOffset),
                'end' => $base['end']->addMonthsNoOverflow($this->cycleOffset),
            ],
            null => null,
        };
    }

    /**
     * @return list<PayCycleDayData>
     */
    #[Computed]
    public function days(): array
    {
        $bounds = $this->bounds; // @phpstan-ignore property.notFound

        if ($bounds === null) {
            return [];
        }

        $today = CarbonImmutable::today();
        $cycleStart = $bounds['start'];
        $cycleEndPayday = $bounds['end'];
        $lastRenderedDay = $cycleEndPayday->subDay();

        $userId = (int) auth()->id();

        $activity = (new DayActivityLoader)->load($cycleStart, $lastRenderedDay, $userId);

        $days = [];
        $cursor = $cycleStart;

        while ($cursor->lessThanOrEqualTo($lastRenderedDay)) {
            $key = $cursor->format('Y-m-d');
            $dayActivity = $activity[$key] ?? DayActivity::empty();

            $hiddenCount = max(0, count($dayActivity->pips) - self::MAX_PIPS_PER_DAY);

            $days[] = new PayCycleDayData(
                iso: $key,
                day: $cursor->day,
                dayName: $cursor->format('D'),
                isoWeekday: $cursor->isoWeekday(),
                isToday: $cursor->isSameDay($today),
                isCycleStart: $cursor->isSameDay($cycleStart),
                isCycleEnd: $cursor->isSameDay($lastRenderedDay),
                isPast: $cursor->lessThan($today),
                pips: $dayActivity->pips,
                hiddenCount: $hiddenCount,
                netCents: $dayActivity->incomeCents - $dayActivity->postedCents,
                incomeCents: $dayActivity->incomeCents,
                postedCents: $dayActivity->postedCents,
                plannedCents: $dayActivity->plannedCents,
            );

            $cursor = $cursor->addDay();
        }

        return $days;
    }

    /**
     * @return array{income: int, posted: int, planned: int, net: int}
     */
    #[Computed]
    public function totals(): array
    {
        $income = 0;
        $posted = 0;
        $planned = 0;

        foreach ($this->days as $day) { // @phpstan-ignore property.notFound
            $income += $day->incomeCents;
            $posted += $day->postedCents;
            $planned += $day->plannedCents;
        }

        return [
            'income' => $income,
            'posted' => $posted,
            'planned' => $planned,
            'net' => $income - $posted,
        ];
    }

    #[Computed]
    public function isCurrentCycle(): bool
    {
        return $this->cycleOffset === 0;
    }

    /**
     * @return array{iso: string, dayLabel: string, dateLabel: string, pips: list<PayCyclePip>, netCents: int, isToday: bool, isCycleEnd: bool}|null
     */
    #[Computed]
    public function selectedDay(): ?array
    {
        if ($this->selectedDate === null) {
            return null;
        }

        foreach ($this->days as $day) { // @phpstan-ignore property.notFound
            if ($day->iso !== $this->selectedDate) {
                continue;
            }

            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $day->iso);

            if (! $parsed instanceof CarbonImmutable) {
                return null;
            }

            return [
                'iso' => $day->iso,
                'dayLabel' => $parsed->format('l'),
                'dateLabel' => $parsed->format('j F'),
                'pips' => $day->pips,
                'netCents' => $day->netCents,
                'isToday' => $day->isToday,
                'isCycleEnd' => $day->isCycleEnd,
            ];
        }

        return null;
    }

    /**
     * @return array{rangeLabel: string, daysUntilPay: int|null}
     */
    #[Computed]
    public function header(): array
    {
        $bounds = $this->bounds; // @phpstan-ignore property.notFound

        if ($bounds === null) {
            return ['rangeLabel' => '', 'daysUntilPay' => null];
        }

        $today = CarbonImmutable::today();
        $end = $bounds['end'];

        return [
            'rangeLabel' => sprintf(
                '%s → %s',
                $bounds['start']->format('j M'),
                $end->format('j M'),
            ),
            'daysUntilPay' => $this->cycleOffset === 0
                ? max(0, (int) $today->diffInDays($end, false))
                : null,
        ];
    }

    public function placeholder(): string
    {
        return <<<'HTML'
            <section class="pay-cycle-cal placeholder">
                <div class="animate-pulse h-6 w-48 rounded bg-(--color-cib-n-100)"></div>
                <div class="mt-3 grid grid-cols-7 gap-1">
                    @for ($i = 0; $i < 14; $i++)
                        <div class="animate-pulse h-20 rounded bg-(--color-cib-n-100)"></div>
                    @endfor
                </div>
            </section>
            HTML;
    }

    public function render(): View
    {
        return view('livewire.dashboard.pay-cycle-calendar', [
            'formatMoney' => MoneyCast::format(...),
        ]);
    }

    private function bustCache(): void
    {
        unset($this->bounds, $this->days, $this->totals, $this->selectedDay, $this->isCurrentCycle, $this->header); // @phpstan-ignore property.notFound
    }
}
