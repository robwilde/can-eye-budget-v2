<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Carbon\Constants\UnitValue;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

final class CalendarView extends Component
{
    public string $currentMonth = '';

    public function mount(): void
    {
        $this->currentMonth = CarbonImmutable::now()->format('Y-m');
    }

    public function previousMonth(): void
    {
        $this->currentMonth = $this->monthStart()->subMonth()->format('Y-m');
        unset($this->calendarData); // @phpstan-ignore property.notFound
    }

    public function nextMonth(): void
    {
        $this->currentMonth = $this->monthStart()->addMonth()->format('Y-m');
        unset($this->calendarData); // @phpstan-ignore property.notFound
    }

    public function goToToday(): void
    {
        $this->currentMonth = CarbonImmutable::now()->format('Y-m');
        unset($this->calendarData); // @phpstan-ignore property.notFound
    }

    #[On('transaction-saved')]
    public function refreshCalendar(): void
    {
        unset($this->calendarData); // @phpstan-ignore property.notFound
    }

    /** @return array{monthLabel: string, weeks: list<list<array{date: int, fullDate: string, isCurrentMonth: bool, isToday: bool, transactions: list<array{id: int|null, category: string, amount: int, direction: string, type: string, source: string, isTransfer: bool, planned_transaction_id: int|null}>}>>, isCurrentMonth: bool} */
    #[Computed(persist: true)]
    public function calendarData(): array
    {
        $monthStart = $this->monthStart();
        $monthEnd = $monthStart->endOfMonth();
        $today = CarbonImmutable::today();

        $gridStart = $monthStart->startOfWeek(UnitValue::MONDAY);
        $gridEnd = $monthEnd->endOfWeek(UnitValue::SUNDAY);

        /** @var Collection<string, Collection<int, Transaction>> $transactionsByDate */
        $transactionsByDate = Transaction::query()
            ->where('user_id', auth()->id())
            ->whereBetween('post_date', [$gridStart, $gridEnd])
            ->with('category:id,name')
            ->orderBy('post_date')
            ->get()
            ->groupBy(fn (Transaction $t) => $t->post_date->format('Y-m-d'));

        /** @var Collection<string, list<array{id: null, category: string, amount: int, direction: string, type: string, source: string, isTransfer: false, planned_transaction_id: int}>> $plannedByDate */
        $plannedByDate = collect();

        $plannedTransactions = PlannedTransaction::query()
            ->where('user_id', auth()->id())
            ->where('is_active', true)
            ->where('start_date', '<=', $gridEnd)
            ->where(fn ($q) => $q->whereNull('until_date')->orWhere('until_date', '>=', $gridStart))
            ->with('category:id,name')
            ->get();

        foreach ($plannedTransactions as $planned) {
            foreach ($planned->occurrencesBetween($gridStart, $gridEnd) as $date) {
                $dateKey = $date->format('Y-m-d');
                $existing = $plannedByDate->get($dateKey, []);
                $existing[] = [
                    'id' => null,
                    'category' => $planned->category?->name ?? $planned->description, // @phpstan-ignore nullsafe.neverNull
                    'amount' => $planned->amount,
                    'direction' => $planned->direction->value,
                    'type' => 'planned',
                    'source' => 'planned',
                    'isTransfer' => false,
                    'planned_transaction_id' => $planned->id,
                ];
                $plannedByDate->put($dateKey, $existing);
            }
        }

        $weeks = [];
        $current = $gridStart;

        while ($current <= $gridEnd) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $dateKey = $current->format('Y-m-d');
                $dayTransactions = $transactionsByDate->get($dateKey, collect());

                $actualTxns = $dayTransactions->map(fn (Transaction $t) => [
                    'id' => $t->id,
                    'category' => $t->category?->name ?? $t->description, // @phpstan-ignore nullsafe.neverNull
                    'amount' => $t->amount,
                    'direction' => $t->direction->value,
                    'type' => 'actual',
                    'source' => $t->source->value,
                    'isTransfer' => $t->transfer_pair_id !== null,
                    'planned_transaction_id' => null,
                ])->values()->all();

                $week[] = [
                    'date' => $current->day,
                    'fullDate' => $dateKey,
                    'isCurrentMonth' => $current->month === $monthStart->month && $current->year === $monthStart->year,
                    'isToday' => $current->isSameDay($today),
                    'transactions' => array_merge($actualTxns, $plannedByDate->get($dateKey, [])),
                ];

                $current = $current->addDay();
            }
            $weeks[] = $week;
        }

        return [
            'monthLabel' => $monthStart->format('F Y'),
            'weeks' => $weeks,
            'isCurrentMonth' => $monthStart->month === $today->month && $monthStart->year === $today->year,
        ];
    }

    public function placeholder(): string
    {
        return <<<'HTML'
            <div>
                <div class="space-y-4">
                    <div class="animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800 h-10 w-48"></div>
                    <div class="animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800 h-96"></div>
                </div>
            </div>
            HTML;
    }

    public function render(): View
    {
        return view('livewire.calendar-view', [
            'formatMoney' => MoneyCast::format(...),
        ]);
    }

    private function monthStart(): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('Y-m', $this->currentMonth);

        if (! $date instanceof CarbonImmutable) {
            $date = CarbonImmutable::now();
            $this->currentMonth = $date->format('Y-m');
        }

        return $date->startOfMonth();
    }
}
