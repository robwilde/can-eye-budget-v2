<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Enums\TransactionDirection;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Services\ReconciliationMatcher;
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

        unset($this->agenda, $this->weekStrip); // @phpstan-ignore property.notFound
    }

    public function previousMonth(): void
    {
        $this->currentMonth = $this->monthStart()->subMonth()->format('Y-m');
        $this->selectedDate = $this->monthStart()->format('Y-m-d');
        unset($this->calendarData, $this->agenda, $this->weekStrip); // @phpstan-ignore property.notFound
    }

    public function nextMonth(): void
    {
        $this->currentMonth = $this->monthStart()->addMonth()->format('Y-m');
        $this->selectedDate = $this->monthStart()->format('Y-m-d');
        unset($this->calendarData, $this->agenda, $this->weekStrip); // @phpstan-ignore property.notFound
    }

    public function goToToday(): void
    {
        $this->currentMonth = CarbonImmutable::now()->format('Y-m');
        $this->selectedDate = CarbonImmutable::today()->format('Y-m-d');
        unset($this->calendarData, $this->agenda, $this->weekStrip); // @phpstan-ignore property.notFound
    }

    #[On('transaction-saved')]
    public function refreshCalendar(): void
    {
        unset($this->calendarData, $this->agenda, $this->weekStrip); // @phpstan-ignore property.notFound
    }

    /** @return array{monthLabel: string, weeks: list<list<array{date: int, fullDate: string, isCurrentMonth: bool, isToday: bool, transactions: list<array{id: int|null, category: string, icon: string|null, amount: int, direction: string, type: string, source: string, isTransfer: bool, planned_transaction_id: int|null, reconciliation_status: string|null, linked_transaction_id: int|null, occurrence_date: string|null}>}>>, isCurrentMonth: bool} */
    #[Computed(persist: true)]
    public function calendarData(): array
    {
        $monthStart = $this->monthStart();
        $monthEnd = $monthStart->endOfMonth();
        $today = CarbonImmutable::today();

        $gridStart = $monthStart->startOfWeek(UnitValue::MONDAY);
        $gridEnd = $monthEnd->endOfWeek(UnitValue::SUNDAY);

        $allTransactions = Transaction::query()
            ->where('user_id', auth()->id())
            ->current()
            ->whereBetween('post_date', [$gridStart, $gridEnd])
            ->with([
                'category:id,name,icon,parent_id',
                'category.parent:id,icon,parent_id',
                'category.parent.parent:id,icon,parent_id',
            ])
            ->orderBy('post_date')
            ->get();

        /** @var Collection<string, Collection<int, Transaction>> $transactionsByDate */
        $transactionsByDate = $allTransactions
            ->groupBy(fn (Transaction $t) => $t->post_date->format('Y-m-d'));

        /** @var Collection<int, Collection<int, Transaction>> $linkedByPlannedId */
        $linkedByPlannedId = $allTransactions
            ->filter(fn (Transaction $t) => $t->planned_transaction_id !== null)
            ->groupBy('planned_transaction_id');

        /** @var Collection<int, Collection<int, Transaction>> $unlinkedByAccount */
        $unlinkedByAccount = $allTransactions
            ->filter(fn (Transaction $t) => $t->planned_transaction_id === null)
            ->groupBy('account_id');

        $plannedByDate = collect();

        $plannedTransactions = PlannedTransaction::query()
            ->where('user_id', auth()->id())
            ->where('is_active', true)
            ->where('start_date', '<=', $gridEnd)
            ->where(fn ($q) => $q->whereNull('until_date')->orWhere('until_date', '>=', $gridStart))
            ->with([
                'category:id,name,icon,parent_id',
                'category.parent:id,icon,parent_id',
                'category.parent.parent:id,icon,parent_id',
            ])
            ->get();

        foreach ($plannedTransactions as $planned) {
            foreach ($planned->occurrencesBetween($gridStart, $gridEnd) as $date) {
                $dateKey = $date->format('Y-m-d');
                $existing = $plannedByDate->get($dateKey, []);

                $reconciliationResult = $this->resolveReconciliationStatus(
                    $planned,
                    $date,
                    $linkedByPlannedId,
                    $unlinkedByAccount,
                );

                $existing[] = [
                    'id' => null,
                    'category' => $planned->category?->name ?? $planned->description, // @phpstan-ignore nullsafe.neverNull
                    'icon' => $planned->category?->resolveIcon(),
                    'amount' => $planned->amount,
                    'direction' => $planned->direction->value,
                    'type' => 'planned',
                    'source' => 'planned',
                    'isTransfer' => $planned->transfer_to_account_id !== null,
                    'planned_transaction_id' => $planned->id,
                    'reconciliation_status' => $reconciliationResult['status'],
                    'linked_transaction_id' => $reconciliationResult['linked_transaction_id'],
                    'occurrence_date' => $dateKey,
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
                    'icon' => $t->category?->resolveIcon(),
                    'amount' => $t->amount,
                    'direction' => $t->direction->value,
                    'type' => 'actual',
                    'source' => $t->source->value,
                    'isTransfer' => $t->transfer_pair_id !== null,
                    'planned_transaction_id' => $t->planned_transaction_id,
                    'reconciliation_status' => null,
                    'linked_transaction_id' => null,
                    'occurrence_date' => null,
                ])->values()->all();

                $week[] = [
                    'date' => $current->day,
                    'fullDate' => $dateKey,
                    'isCurrentMonth' => $current->month === $monthStart->month && $current->year === $monthStart->year,
                    'isToday' => $current->isSameDay($today),
                    'transactions' => array_merge($actualTxns, (array) $plannedByDate->get($dateKey, [])),
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

    /**
     * @return list<array{date: string, dayOfMonth: int, dayName: string, isToday: bool, isPayday: bool, isSelected: bool, dots: list<string>}>
     */
    #[Computed]
    public function weekStrip(): array
    {
        $today = CarbonImmutable::today();
        $start = $today->subDays(2);

        $user = auth()->user();
        $paydayKey = $user?->next_pay_date?->format('Y-m-d');

        /** @var Collection<string, array<string, mixed>> $byDate */
        $byDate = collect($this->calendarData['weeks']) // @phpstan-ignore property.notFound, argument.templateType, argument.templateType
            ->flatten(1)
            ->keyBy('fullDate');

        $cells = [];

        for ($i = 0; $i < 7; $i++) {
            $cursor = $start->addDays($i);
            $key = $cursor->format('Y-m-d');

            /** @var list<array<string, mixed>> $txns */
            $txns = $byDate->get($key)['transactions'] ?? [];

            $dots = [];

            if (collect($txns)->contains(fn (array $t): bool => ($t['direction'] ?? null) === 'credit' && ($t['type'] ?? 'actual') === 'actual')) {
                $dots[] = 'income';
            }

            if (collect($txns)->contains(fn (array $t): bool => ($t['direction'] ?? null) === 'debit' && ($t['type'] ?? 'actual') === 'actual')) {
                $dots[] = 'posted';
            }

            if (collect($txns)->contains(fn (array $t): bool => ($t['type'] ?? 'actual') === 'planned')) {
                $dots[] = 'planned';
            }

            $cells[] = [
                'date' => $key,
                'dayOfMonth' => $cursor->day,
                'dayName' => $cursor->format('D'),
                'isToday' => $cursor->isSameDay($today),
                'isPayday' => $paydayKey === $key,
                'isSelected' => $this->selectedDate === $key,
                'dots' => $dots,
            ];
        }

        return $cells;
    }

    /**
     * @return list<array{date: string, heading: string, net: int, transactions: list<array<string, mixed>>}>
     */
    #[Computed]
    public function agenda(): array
    {
        $fromKey = $this->selectedDate;

        return collect($this->calendarData['weeks']) // @phpstan-ignore property.notFound, argument.templateType, argument.templateType
            ->flatten(1)
            ->filter(fn (array $d): bool => $d['fullDate'] >= $fromKey)
            ->filter(fn (array $d): bool => count($d['transactions']) > 0)
            ->sortBy('fullDate')
            ->values()
            ->map(function (array $d): array {
                $net = (int) collect($d['transactions']) // @phpstan-ignore argument.templateType, argument.templateType
                    ->sum(fn (array $t): int => (($t['direction'] ?? null) === 'credit' ? 1 : -1) * abs((int) $t['amount']));

                return [
                    'date' => $d['fullDate'],
                    'heading' => CarbonImmutable::parse($d['fullDate'])->format('j D M'),
                    'net' => $net,
                    'transactions' => $d['transactions'],
                ];
            })
            ->all();
    }

    /**
     * @return array{income: int, posted: int, planned: int, bufferAtPayday: int|null}
     */
    #[Computed]
    public function quickline(): array
    {
        $user = auth()->user();
        $bounds = $user?->currentPayCycleBounds();

        if ($user === null || $bounds === null) {
            return [
                'income' => 0,
                'posted' => 0,
                'planned' => 0,
                'bufferAtPayday' => null,
            ];
        }

        $txns = Transaction::query()
            ->where('user_id', $user->id)
            ->current()
            ->whereBetween('post_date', [$bounds['start'], $bounds['end']])
            ->get(['amount', 'direction']);

        $income = (int) $txns
            ->where('direction', TransactionDirection::Credit)
            ->sum('amount');

        $posted = (int) $txns
            ->where('direction', TransactionDirection::Debit)
            ->sum(fn (Transaction $t): int => abs((int) $t->amount));

        $planned = (int) PlannedTransaction::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where('direction', TransactionDirection::Debit)
            ->where('start_date', '<=', $bounds['end'])
            ->where(fn ($q) => $q->whereNull('until_date')->orWhere('until_date', '>=', $bounds['start']))
            ->get()
            ->sum(fn (PlannedTransaction $pt): int => $pt->occurrencesBetween($bounds['start'], $bounds['end'])->count() * abs((int) $pt->amount));

        return [
            'income' => $income,
            'posted' => $posted,
            'planned' => $planned,
            'bufferAtPayday' => $user->bufferUntilNextPay($user->totalAvailable()),
        ];
    }

    /**
     * @return array{label: string, rangeLabel: string|null}
     */
    #[Computed]
    public function headerLabel(): array
    {
        $month = $this->monthStart();
        $bounds = auth()->user()?->currentPayCycleBounds();

        $rangeLabel = $bounds
            ? sprintf('Pay cycle · %s → %s', $bounds['start']->format('j M'), $bounds['end']->format('j M'))
            : null;

        return [
            'label' => $month->format('F Y'),
            'rangeLabel' => $rangeLabel,
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
                    <div class="animate-pulse h-9 w-32 rounded-full bg-(--color-cib-n-100)"></div>
                </div>
                <div class="flex gap-2">
                    <div class="animate-pulse h-16 w-14 rounded-xl bg-(--color-cib-n-100)"></div>
                    <div class="animate-pulse h-16 w-14 rounded-xl bg-(--color-cib-n-100)"></div>
                    <div class="animate-pulse h-16 w-14 rounded-xl bg-(--color-cib-n-100)"></div>
                    <div class="animate-pulse h-16 w-14 rounded-xl bg-(--color-cib-n-100)"></div>
                    <div class="animate-pulse h-16 w-14 rounded-xl bg-(--color-cib-n-100)"></div>
                    <div class="animate-pulse h-16 w-14 rounded-xl bg-(--color-cib-n-100)"></div>
                    <div class="animate-pulse h-16 w-14 rounded-xl bg-(--color-cib-n-100)"></div>
                </div>
                <div class="animate-pulse h-40 rounded-xl border-2 border-(--color-border-strong) bg-(--color-bg-surface)"></div>
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
     * @param  Collection<int, Collection<int, Transaction>>  $linkedByPlannedId
     * @param  Collection<int, Collection<int, Transaction>>  $unlinkedByAccount
     * @return array{status: string, linked_transaction_id: int|null}
     */
    private function resolveReconciliationStatus(
        PlannedTransaction $planned,
        CarbonImmutable $occurrenceDate,
        Collection $linkedByPlannedId,
        Collection $unlinkedByAccount,
    ): array {
        $linked = $linkedByPlannedId->get($planned->id, collect())
            ->first(fn (Transaction $t) => abs($t->post_date->diffInDays($occurrenceDate)) <= ReconciliationMatcher::DATE_TOLERANCE_DAYS);

        if ($linked) {
            return ['status' => 'reconciled', 'linked_transaction_id' => $linked->id];
        }

        $hasSuggestion = $unlinkedByAccount->get($planned->account_id, collect())
            ->contains(function (Transaction $t) use ($planned, $occurrenceDate) {
                if ($t->direction !== $planned->direction) {
                    return false;
                }

                if (abs($t->post_date->diffInDays($occurrenceDate)) > ReconciliationMatcher::DATE_TOLERANCE_DAYS) {
                    return false;
                }

                $amountDiff = abs(abs($t->amount) - abs($planned->amount));

                return $amountDiff <= abs($planned->amount) * ReconciliationMatcher::AMOUNT_TOLERANCE;
            });

        return ['status' => $hasSuggestion ? 'suggested' : 'unreconciled', 'linked_transaction_id' => null];
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
}
