<?php

declare(strict_types=1);

namespace App\Services\Bills;

use App\DTOs\BillRow;
use App\DTOs\BillsKpis;
use App\DTOs\BillsOverview;
use App\DTOs\SubscriptionRollup;
use App\Enums\OccurrenceState;
use App\Enums\OccurrenceStatus;
use App\Enums\PlannedTransactionStatus;
use App\Enums\PlannedTransactionType;
use App\Enums\TransactionDirection;
use App\Models\PlannedOccurrence;
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\PlannedTransactionPriceChange;
use App\Models\User;
use App\Services\Recurring\OccurrenceStateResolver;
use App\Services\Recurring\PlanAmountResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The one read-only service that answers every Bills question from stored occurrences and payments: the overview
 * sections, the KPIs, the all-bills list, the subscription rollup and the figure budgets reserve for bills. Paid
 * state is read from the occurrences and their payments only, so Bills, the calendar and budgets cannot disagree.
 * Everything is scoped to the user through their plans.
 */
final class BillsOverviewService
{
    public const int HORIZON_DAYS = 90;

    public const int NEXT_UP = 4;

    public const int PRICE_CHANGE_LIMIT = 10;

    public const int PRICE_CHANGE_MONTHS = 12;

    public function __construct(private readonly OccurrenceStateResolver $states, private readonly PlanAmountResolver $amounts) {}

    public function overview(User $user, ?CarbonImmutable $today = null): BillsOverview
    {
        $today ??= $user->today();
        $monthStart = $today->startOfMonth();
        $monthEnd = $today->endOfMonth()->startOfDay();
        $rows = $this->openRows($user, $monthStart, $today->addDays(self::HORIZON_DAYS), $today);
        $paidRows = $this->paidRows($user, $monthStart, $monthEnd, $today);

        $active = $rows->filter(static fn (BillRow $row): bool => $row->state !== OccurrenceState::Dormant);
        $pastDue = $active->filter(static fn (BillRow $row): bool => $row->dueOn->lessThan($today))->values();
        $thisMonthOpen = $active->filter(static fn (BillRow $row): bool => $row->dueOn->greaterThanOrEqualTo($today) && $row->dueOn->lessThanOrEqualTo($monthEnd))->values();
        $thisMonth = collect([...$thisMonthOpen->all(), ...$paidRows->all()])->sortBy(static fn (BillRow $row): string => $row->dueOn->format('Y-m-d').'|'.$row->planId)->values();
        $later = $active->filter(static fn (BillRow $row): bool => $row->dueOn->greaterThan($monthEnd))->unique('planId')->values();
        $dormant = $rows->filter(static fn (BillRow $row): bool => $row->state === OccurrenceState::Dormant)->unique('planId')->values();
        $nextUp = $active->filter(static fn (BillRow $row): bool => $row->dueOn->greaterThanOrEqualTo($today))->take(self::NEXT_UP)->values();

        $monthOwed = $active->filter(static fn (BillRow $row): bool => $row->dueOn->lessThanOrEqualTo($monthEnd));
        $paidThisMonth = $this->paidBetween($user, $monthStart, $monthEnd);
        $owed = $monthOwed->filter(static fn (BillRow $row): bool => $row->remaining > 0);

        return new BillsOverview(
            pastDue: $pastDue->all(),
            thisMonth: $thisMonth->all(),
            later: $later->all(),
            dormant: $dormant->all(),
            nextUp: $nextUp->all(),
            monthPaid: $paidThisMonth,
            monthOverdue: (int) $pastDue->sum('remaining'),
            monthToCome: (int) $thisMonthOpen->sum('remaining'),
            kpis: new BillsKpis(
                remainingThisMonth: (int) $monthOwed->sum('remaining'),
                paidThisMonth: $paidThisMonth,
                dueNextSevenDays: (int) $active->filter(static fn (BillRow $row): bool => $row->dueOn->greaterThanOrEqualTo($today) && $row->dueOn->lessThanOrEqualTo($today->addDays(7)))->sum('remaining'),
                pastDueTotal: (int) $pastDue->sum('remaining'),
                countOwed: $owed->count(),
                countNeedingAction: $owed->reject(static fn (BillRow $row): bool => $row->autopay)->count(),
            ),
        );
    }

    /**
     * Every bill of the user as one row each, for the All bills tab. Filters: a text that matches the name or the
     * merchant key, the payment state of the plan's next open bill, the lifecycle (paused or ended) and the type.
     * Sorted by name or amount.
     *
     * @return Collection<int, BillRow>
     */
    public function allBills(User $user, ?string $search = null, ?OccurrenceState $payment = null, ?string $lifecycle = null, ?PlannedTransactionType $type = null, string $sort = 'name', bool $descending = false, ?CarbonImmutable $today = null): Collection
    {
        $today ??= $user->today();
        $term = $search === null ? '' : mb_trim($search);

        $plans = PlannedTransaction::query()
            ->where('user_id', $user->id)
            ->where('direction', 'debit')
            ->where('type', '!=', PlannedTransactionType::Income->value)
            ->where(fn (Builder $query): Builder => $query->whereNull('transfer_to_account_id')->orWhereHas('transferToAccount', fn (Builder $destination): Builder => $destination->whereIn('type', ['credit-card', 'loan', 'mortgage'])))
            ->when($lifecycle === 'ended', fn (Builder $query): Builder => $query->where('status', PlannedTransactionStatus::Ended->value))
            ->when($lifecycle === 'paused', fn (Builder $query): Builder => $query->where('status', PlannedTransactionStatus::Inactive->value))
            ->when($lifecycle === null, fn (Builder $query): Builder => $query->visible())
            ->when($type instanceof PlannedTransactionType, fn (Builder $query): Builder => $query->where('type', $type->value))
            ->when($term !== '', fn (Builder $query): Builder => $query->where(fn (Builder $match): Builder => $match
                ->whereRaw("description LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%'])
                ->orWhereRaw("merchant_key LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%'])))
            ->get();

        $next = $this->nextOpenByPlan($plans, $today);

        $rows = $plans->map(function (PlannedTransaction $plan) use ($next): BillRow {
            $row = $next[$plan->id] ?? null;

            return $row ?? new BillRow($plan->id, null, $plan->description, $plan->type, $plan->start_date->toImmutable(), abs($plan->amount), 0, 0, $plan->isActive() ? OccurrenceState::Upcoming : OccurrenceState::Dormant, $plan->autopay);
        });

        if ($payment instanceof OccurrenceState) {
            $rows = $rows->filter(static fn (BillRow $row): bool => $row->occurrenceId !== null && $row->state === $payment);
        }

        $rows = $sort === 'amount'
            ? $rows->sortBy(static fn (BillRow $row): int => $row->expected, descending: $descending)
            : $rows->sortBy(static fn (BillRow $row): string => mb_strtolower($row->name), descending: $descending);

        return $rows->values();
    }

    /**
     * One plan's current bill: its earliest open occurrence from the start of the month on, else its latest settled
     * one. Null when it has neither.
     */
    public function currentFor(PlannedTransaction $plan, ?CarbonImmutable $today = null): ?BillRow
    {
        return $this->nextOpenByPlan(collect([$plan]), $today ?? $plan->user->today())[$plan->id] ?? null;
    }

    /**
     * Active subscriptions normalised to a month and a year from their cadence, and the ten most recent price
     * changes of the last twelve months across the user's subscriptions.
     */
    public function subscriptions(User $user, ?CarbonImmutable $today = null): SubscriptionRollup
    {
        $today ??= $user->today();
        $plans = PlannedTransaction::query()->where('user_id', $user->id)->active()->where('type', PlannedTransactionType::Subscription->value)->get()
            ->filter(static fn (PlannedTransaction $plan): bool => ($end = $plan->endsOn()) === null || $end->greaterThanOrEqualTo($today))
            ->values();

        $annual = (int) round($plans->sum(static fn (PlannedTransaction $plan): float => abs($plan->amount) * $plan->frequency->timesPerYear($plan->recurrence_interval, count($plan->days_of_month ?? []))));

        $changes = PlannedTransactionPriceChange::query()
            ->forUser($user)
            ->whereIn('planned_transaction_id', PlannedTransaction::query()->where('user_id', $user->id)->visible()->where('type', PlannedTransactionType::Subscription->value)->select('id'))
            ->whereDate('effective_on', '>=', $today->subMonths(self::PRICE_CHANGE_MONTHS)->toDateString())
            ->with('plannedTransaction:id,description')
            ->orderByDesc('effective_on')
            ->orderByDesc('id')
            ->limit(self::PRICE_CHANGE_LIMIT)
            ->get();

        return new SubscriptionRollup($plans->count(), (int) round($annual / 12), $annual, $changes);
    }

    /**
     * What budgets reserve for bills in a category over a period: the unpaid remainder of the open bills of
     * active plans in those categories. Card, loan and mortgage payments are bills but not spend, so plans that
     * are not countable (as everywhere else) reserve nothing.
     *
     * @param  list<int>  $categoryIds
     */
    public function billsReserved(User $user, array $categoryIds, CarbonImmutable $start, CarbonImmutable $end): int
    {
        if ($categoryIds === []) {
            return 0;
        }

        $plans = PlannedTransaction::query()
            ->where('user_id', $user->id)
            ->active()
            ->payable()
            ->whereIn('category_id', $categoryIds)
            ->countable()
            ->with(['account:id,is_tracked', 'transferToAccount:id,is_tracked'])
            ->get()
            ->filter(static fn (PlannedTransaction $plan): bool => $plan->countedDirection() === TransactionDirection::Debit)
            ->values();

        if ($plans->isEmpty()) {
            return 0;
        }

        $histories = $this->amounts->forPlans($plans);

        return (int) $this->occurrenceQuery($start, $end)
            ->whereIn('planned_transaction_id', $plans->pluck('id')->all())
            ->get()
            ->sum(fn (PlannedOccurrence $occurrence): int => $this->rowAmounts($occurrence, $histories)['remaining']);
    }

    /** @return Collection<int, BillRow> */
    private function openRows(User $user, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today): Collection
    {
        $occurrences = $this->occurrenceQuery($from, $to)
            ->whereIn('planned_transaction_id', PlannedTransaction::query()->where('user_id', $user->id)->payable()->select('id'))
            ->get();

        return $this->rows($occurrences, $today);
    }

    /**
     * Occurrences of active payable plans that were settled and fall due in the range, for This month.
     *
     * @return Collection<int, BillRow>
     */
    private function paidRows(User $user, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today): Collection
    {
        $occurrences = PlannedOccurrence::query()
            ->where('status', OccurrenceStatus::Paid->value)
            ->whereIn('planned_transaction_id', PlannedTransaction::query()->where('user_id', $user->id)->payable()->active()->select('id'))
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $unsnoozed): Builder => $unsnoozed->whereNull('snoozed_until')->whereDate('due_date', '>=', $from->toDateString())->whereDate('due_date', '<=', $to->toDateString()))
                ->orWhere(fn (Builder $snoozed): Builder => $snoozed->whereDate('snoozed_until', '>=', $from->toDateString())->whereDate('snoozed_until', '<=', $to->toDateString())))
            ->withSum('payments', 'amount')
            ->with('plannedTransaction')
            ->get();

        return $this->rows($occurrences, $today);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, PlannedOccurrence>  $occurrences
     * @return Collection<int, BillRow>
     */
    private function rows(\Illuminate\Database\Eloquent\Collection $occurrences, CarbonImmutable $today): Collection
    {
        $histories = $this->amounts->forPlans($occurrences->pluck('plannedTransaction')->unique('id')->values());

        return $occurrences
            ->sortBy(static fn (PlannedOccurrence $occurrence): string => $occurrence->effectiveDueDate()->format('Y-m-d').'|'.mb_str_pad((string) $occurrence->id, 12, '0', STR_PAD_LEFT))
            ->map(fn (PlannedOccurrence $occurrence): BillRow => $this->row($occurrence, $today, $histories))
            ->values();
    }

    /**
     * Each plan's current bill: its earliest open occurrence from the start of the month on, else its latest settled
     * one, so a plan paid for the cycle still reads as paid.
     *
     * @param  Collection<int, PlannedTransaction>  $plans
     * @return array<int, BillRow>
     */
    private function nextOpenByPlan(Collection $plans, CarbonImmutable $today): array
    {
        $first = $today->startOfMonth()->toDateString();

        $rows = PlannedOccurrence::query()
            ->whereIn('status', [OccurrenceStatus::Scheduled->value, OccurrenceStatus::Paid->value])
            ->whereIn('planned_transaction_id', $plans->pluck('id')->all())
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $unsnoozed): Builder => $unsnoozed->whereNull('snoozed_until')->whereDate('due_date', '>=', $first))
                ->orWhereDate('snoozed_until', '>=', $first))
            ->withSum('payments', 'amount')
            ->with('plannedTransaction')
            ->get();
        $histories = $this->amounts->forPlans($plans);
        $sorted = $rows->sortBy(static fn (PlannedOccurrence $occurrence): string => $occurrence->effectiveDueDate()->format('Y-m-d').'|'.mb_str_pad((string) $occurrence->id, 12, '0', STR_PAD_LEFT));

        $next = [];

        foreach ($sorted->where('status', OccurrenceStatus::Scheduled) as $occurrence) {
            $next[$occurrence->planned_transaction_id] ??= $this->row($occurrence, $today, $histories);
        }

        foreach ($sorted->where('status', OccurrenceStatus::Paid)->reverse() as $occurrence) {
            $next[$occurrence->planned_transaction_id] ??= $this->row($occurrence, $today, $histories);
        }

        return $next;
    }

    /** @return Builder<PlannedOccurrence> */
    private function occurrenceQuery(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        $first = $from->toDateString();
        $last = $to->toDateString();

        return PlannedOccurrence::query()
            ->where('status', OccurrenceStatus::Scheduled->value)
            ->whereNull('closed_at')
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $unsnoozed): Builder => $unsnoozed->whereNull('snoozed_until')->whereDate('due_date', '>=', $first)->whereDate('due_date', '<=', $last))
                ->orWhere(fn (Builder $snoozed): Builder => $snoozed->whereDate('snoozed_until', '>=', $first)->whereDate('snoozed_until', '<=', $last)))
            ->withSum('payments', 'amount')
            ->with('plannedTransaction');
    }

    /** @param  array<int, int>  $histories */
    private function row(PlannedOccurrence $occurrence, CarbonImmutable $today, array $histories): BillRow
    {
        $plan = $occurrence->plannedTransaction;
        ['expected' => $expected, 'paid' => $paid, 'remaining' => $remaining] = $this->rowAmounts($occurrence, $histories);

        return new BillRow(
            planId: $plan->id,
            occurrenceId: $occurrence->id,
            name: $plan->description,
            type: $plan->type,
            dueOn: $occurrence->effectiveDueDate(),
            expected: $expected,
            paid: $paid,
            remaining: $remaining,
            state: $this->states->resolve($occurrence, $today, $paid, $expected),
            autopay: $plan->autopay,
        );
    }

    /**
     * What an occurrence expects (its own amount, else what the plan's history says, else the plan's), what is paid
     * and what is left, in cents.
     *
     * @param  array<int, int>  $histories
     * @return array{expected: int, paid: int, remaining: int}
     */
    private function rowAmounts(PlannedOccurrence $occurrence, array $histories): array
    {
        $expected = abs($occurrence->expected_amount ?? $histories[$occurrence->planned_transaction_id] ?? $occurrence->plannedTransaction->amount);
        $paid = (int) $occurrence->payments_sum_amount;

        return ['expected' => $expected, 'paid' => $paid, 'remaining' => max($expected - $paid, 0)];
    }

    private function paidBetween(User $user, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) PlannedPayment::query()
            ->forUser($user)
            ->whereIn('planned_occurrence_id', PlannedOccurrence::query()->whereIn('planned_transaction_id', PlannedTransaction::query()->where('user_id', $user->id)->payable()->select('id'))->select('id'))
            ->whereDate('paid_on', '>=', $from->toDateString())
            ->whereDate('paid_on', '<=', $to->toDateString())
            ->sum('amount');
    }
}
