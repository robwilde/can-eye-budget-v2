<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Enums\PayFrequency;
use App\Livewire\Dashboard\Data\PayCyclePip;
use App\Livewire\Data\CalendarDayData;
use App\Models\User;
use App\Support\Calendar\DayActivity;
use App\Support\Calendar\DayActivityLoader;
use Carbon\CarbonImmutable;
use Carbon\Constants\UnitValue;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

final class CalendarView extends Component
{
    public const int MAX_PIPS_PER_DAY = 3;

    public string $currentMonth = '';

    public string $selectedDate = '';

    public function mount(): void
    {
        $this->currentMonth = CarbonImmutable::now()->format('Y-m');
        $this->selectedDate = CarbonImmutable::today()->format('Y-m-d');
    }

    public function selectDate(string $date): void
    {
        $parsed = CarbonImmutable::createFromFormat('Y-m-d', $date);

        $this->selectedDate = $parsed instanceof CarbonImmutable
            ? $parsed->format('Y-m-d')
            : CarbonImmutable::today()->format('Y-m-d');

        unset($this->selectedDay); // @phpstan-ignore property.notFound
    }

    public function previousMonth(): void
    {
        $target = $this->monthStart()->subMonth();
        $this->currentMonth = $target->format('Y-m');
        $this->selectedDate = $target->format('Y-m-d');
        $this->bustCache();
    }

    public function nextMonth(): void
    {
        $target = $this->monthStart()->addMonth();
        $this->currentMonth = $target->format('Y-m');
        $this->selectedDate = $target->format('Y-m-d');
        $this->bustCache();
    }

    public function goToToday(): void
    {
        $this->currentMonth = CarbonImmutable::now()->format('Y-m');
        $this->selectedDate = CarbonImmutable::today()->format('Y-m-d');
        $this->bustCache();
    }

    #[On('transaction-saved')]
    public function refreshCalendar(): void
    {
        $this->bustCache();
    }

    /**
     * @return list<CalendarDayData>
     */
    #[Computed]
    public function days(): array
    {
        $monthStart = $this->monthStart();
        $monthEnd = $monthStart->endOfMonth();
        $today = CarbonImmutable::today();

        $gridStart = $monthStart->startOfWeek(UnitValue::MONDAY);
        $gridEnd = $monthEnd->endOfWeek(UnitValue::SUNDAY);

        $userId = (int) auth()->id();
        $user = auth()->user();

        $activity = (new DayActivityLoader)->load($gridStart, $gridEnd, $userId);

        $paydays = $user instanceof User
            ? $this->paydaysWithinGrid($gridStart, $gridEnd, $user)
            : [];

        $activeCycle = $user?->currentPayCycleBounds();

        $days = [];
        $cursor = $gridStart;

        while ($cursor->lessThanOrEqualTo($gridEnd)) {
            $key = $cursor->format('Y-m-d');
            $dayActivity = $activity[$key] ?? DayActivity::empty();

            $hiddenCount = max(0, count($dayActivity->pips) - self::MAX_PIPS_PER_DAY);

            $isCurrentMonth = $cursor->month === $monthStart->month && $cursor->year === $monthStart->year;
            $paydayKind = $paydays[$key] ?? null;
            $isNextPayday = $paydayKind === 'next' && $isCurrentMonth;

            $isInActiveCycle = $activeCycle !== null
                && $cursor->greaterThanOrEqualTo($activeCycle['start'])
                && $cursor->lessThan($activeCycle['end']);

            $days[] = new CalendarDayData(
                iso: $key,
                day: $cursor->day,
                dayName: $cursor->format('D'),
                isoWeekday: $cursor->isoWeekday(),
                isToday: $cursor->isSameDay($today),
                isPast: $cursor->lessThan($today),
                isCurrentMonth: $isCurrentMonth,
                isPastPayday: $paydayKind === 'past',
                isNextPayday: $isNextPayday,
                isInActiveCycle: $isInActiveCycle,
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
     * @return array{income: int, spend: int, net: int}
     */
    #[Computed]
    public function monthTotals(): array
    {
        $income = 0;
        $spend = 0;

        foreach ($this->days as $day) { // @phpstan-ignore property.notFound
            if (! $day->isCurrentMonth) {
                continue;
            }

            $income += $day->incomeCents;
            $spend += $day->postedCents;
        }

        return [
            'income' => $income,
            'spend' => $spend,
            'net' => $income - $spend,
        ];
    }

    /**
     * @return array{iso: string, dayLabel: string, dateLabel: string, pips: list<PayCyclePip>, netCents: int, isToday: bool, isPastPayday: bool, isNextPayday: bool}|null
     */
    #[Computed]
    public function selectedDay(): ?array
    {
        if ($this->selectedDate === '') {
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
                'isPastPayday' => $day->isPastPayday,
                'isNextPayday' => $day->isNextPayday,
            ];
        }

        return null;
    }

    /**
     * @return array{label: string, isCurrentMonth: bool}
     */
    #[Computed]
    public function headerLabel(): array
    {
        $month = $this->monthStart();
        $today = CarbonImmutable::today();

        return [
            'label' => $month->format('F Y'),
            'isCurrentMonth' => $month->month === $today->month && $month->year === $today->year,
        ];
    }

    public function placeholder(): string
    {
        return <<<'HTML'
            <div class="space-y-4">
                <div class="animate-pulse h-8 w-48 rounded-lg bg-(--color-cib-n-100)"></div>
                <div class="flex gap-2">
                    <div class="animate-pulse h-9 w-28 rounded-full bg-(--color-cib-n-100)"></div>
                    <div class="animate-pulse h-9 w-28 rounded-full bg-(--color-cib-n-100)"></div>
                    <div class="animate-pulse h-9 w-28 rounded-full bg-(--color-cib-n-100)"></div>
                </div>
                <div class="animate-pulse h-96 rounded-xl border-2 border-(--color-border-strong) bg-(--color-bg-surface)"></div>
            </div>
            HTML;
    }

    public function render(): View
    {
        return view('livewire.calendar-view', [
            'formatMoney' => MoneyCast::format(...),
        ]);
    }

    /**
     * Walk back/forward from the user's next_pay_date by pay_frequency to flag every payday
     * inside the visible grid. Returns a map of iso-date => 'past' | 'next'. The 'next' tag
     * is reserved for the single soonest payday >= today (only one cell ever wears it).
     *
     * @return array<string, 'past'|'next'>
     */
    private function paydaysWithinGrid(CarbonImmutable $gridStart, CarbonImmutable $gridEnd, User $user): array
    {
        if ($user->next_pay_date === null || $user->pay_frequency === null || ! $user->hasPayCycleConfigured()) {
            return [];
        }

        $frequency = $user->pay_frequency;
        $today = CarbonImmutable::today();
        $anchor = CarbonImmutable::instance($user->next_pay_date);

        $step = static fn (CarbonImmutable $date, int $direction): CarbonImmutable => match ($frequency) {
            PayFrequency::Weekly => $date->addWeeks($direction),
            PayFrequency::Fortnightly => $date->addWeeks($direction * 2),
            PayFrequency::Monthly => $date->addMonthsNoOverflow($direction),
        };

        $maxIterations = 500;

        $cursor = $anchor;
        $iterations = 0;
        while ($cursor->greaterThan($gridStart) && $iterations++ < $maxIterations) {
            $cursor = $step($cursor, -1);
        }

        /** @var array<string, 'past'|'next'> $paydays */
        $paydays = [];
        $nextAssigned = false;
        $iterations = 0;

        while ($cursor->lessThanOrEqualTo($gridEnd) && $iterations++ < $maxIterations) {
            if ($cursor->greaterThanOrEqualTo($gridStart)) {
                $key = $cursor->format('Y-m-d');

                if ($cursor->lessThan($today)) {
                    $paydays[$key] = 'past';
                } elseif (! $nextAssigned) {
                    $paydays[$key] = 'next';
                    $nextAssigned = true;
                }
            }

            $cursor = $step($cursor, 1);
        }

        return $paydays;
    }

    private function monthStart(): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('Y-m-d', $this->currentMonth.'-01');

        if (! $date instanceof CarbonImmutable) {
            $date = CarbonImmutable::now();
            $this->currentMonth = $date->format('Y-m');
        }

        return $date->startOfMonth();
    }

    private function bustCache(): void
    {
        unset($this->days, $this->monthTotals, $this->selectedDay, $this->headerLabel); // @phpstan-ignore property.notFound
    }
}
