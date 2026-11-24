<?php

declare(strict_types=1);

namespace App\Services\Bills;

use App\DTOs\BillRow;
use App\DTOs\BillsMonth;
use App\DTOs\UpcomingRecurringItem;
use App\Enums\OccurrenceStatus;
use App\Enums\TransactionDirection;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\Recurring\OccurrenceStateResolver;
use App\Services\Recurring\PlanAmountResolver;
use App\Services\Recurring\PlannedOccurrenceGenerator;
use Carbon\CarbonImmutable;
use Carbon\Constants\UnitValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The Bills calendar and the projected recurring items of the next days. The calendar is a Monday-first month
 * grid with one chip per bill on its effective due day; browsing generates the month's occurrences on demand,
 * up to PlannedOccurrenceGenerator::CALENDAR_MONTHS ahead. Everything is read from stored occurrences of the
 * user's own plans.
 */
final readonly class BillsCalendarService
{
    public const int LOOKBACK_MONTHS = 24;

    public const int PROJECTION_DAYS = 10;

    public function __construct(private PlannedOccurrenceGenerator $generator, private OccurrenceStateResolver $states, private PlanAmountResolver $amounts) {}

    /**
     * The month to show for a requested 'Y-m': an invalid one is the current month, one beyond the forward limit
     * stops at it and one before the back limit stops there.
     */
    public function resolveMonth(User $user, string $requested): CarbonImmutable
    {
        $today = $user->today();
        $first = $today->startOfMonth();
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested) === 1
            ? CarbonImmutable::createFromFormat('!Y-m', $requested, $today->timezone)
            : false;

        if (! $month instanceof CarbonImmutable) {
            return $first;
        }

        $latest = $first->addMonthsNoOverflow(PlannedOccurrenceGenerator::CALENDAR_MONTHS);
        $earliest = $first->subMonthsNoOverflow(self::LOOKBACK_MONTHS);

        return $month->greaterThan($latest) ? $latest : ($month->lessThan($earliest) ? $earliest : $month);
    }

    public function month(User $user, string $requested): BillsMonth
    {
        $today = $user->today();
        $first = $this->resolveMonth($user, $requested);
        $last = $first->endOfMonth()->startOfDay();

        if ($last->greaterThan($today->addDays(PlannedOccurrenceGenerator::HORIZON_DAYS - 1))) {
            $this->generator->ensureThrough($user, $last);
        }

        $rows = $this->rows($user, $first, $last, $today)->groupBy(static fn (BillRow $row): string => $row->dueOn->format('Y-m-d'));
        $gridStart = $first->startOfWeek(UnitValue::MONDAY);
        $gridEnd = $last->endOfWeek(UnitValue::SUNDAY)->startOfDay();
        $days = [];

        for ($date = $gridStart; $date->lessThanOrEqualTo($gridEnd); $date = $date->addDay()) {
            $days[] = ['date' => $date, 'inMonth' => $date->month === $first->month, 'bills' => ($rows->get($date->format('Y-m-d')) ?? collect())->values()->all()];
        }

        $all = $rows->flatten(1);
        $firstOfThisMonth = $today->startOfMonth();

        return new BillsMonth(
            month: $first->format('Y-m'),
            label: $first->format('F Y'),
            days: $days,
            count: $all->count(),
            expected: (int) $all->sum('expected'),
            paid: (int) $all->sum('paid'),
            isCurrent: $first->equalTo($firstOfThisMonth),
            canGoForward: $first->lessThan($firstOfThisMonth->addMonthsNoOverflow(PlannedOccurrenceGenerator::CALENDAR_MONTHS)),
            canGoBack: $first->greaterThan($firstOfThisMonth->subMonthsNoOverflow(self::LOOKBACK_MONTHS)),
        );
    }

    /**
     * Active recurring items (bills and income) due in the next days, from stored open occurrences. An item whose
     * past payments varied shows the low and high of what it cost.
     *
     * @return list<UpcomingRecurringItem>
     */
    public function upcoming(User $user, ?CarbonImmutable $today = null, int $days = self::PROJECTION_DAYS): array
    {
        $today ??= $user->today();
        $end = $today->addDays($days);
        $plans = PlannedTransaction::query()->where('user_id', $user->id)->active()
            ->where(fn (Builder $query): Builder => $query->excludingTransfers()->orWhere(fn (Builder $repayments): Builder => $repayments->whereNotNull('transfer_to_account_id')->billShaped()))
            ->select('id');

        $open = PlannedOccurrence::query()
            ->where('status', OccurrenceStatus::Scheduled->value)
            ->whereIn('planned_transaction_id', $plans)
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $unsnoozed): Builder => $unsnoozed->whereNull('snoozed_until')->whereDate('due_date', '>=', $today->toDateString())->whereDate('due_date', '<=', $end->toDateString()))
                ->orWhere(fn (Builder $snoozed): Builder => $snoozed->whereDate('snoozed_until', '>=', $today->toDateString())->whereDate('snoozed_until', '<=', $end->toDateString())))
            ->with('plannedTransaction')
            ->get();

        if ($open->isEmpty()) {
            return [];
        }

        $ranges = $this->paidRanges($open->pluck('planned_transaction_id')->unique()->values()->all());
        $histories = $this->amounts->forPlans($open->pluck('plannedTransaction')->unique('id')->values());

        return $open
            ->sortBy(static fn (PlannedOccurrence $occurrence): string => $occurrence->effectiveDueDate()->format('Y-m-d').'|'.$occurrence->id)
            ->map(function (PlannedOccurrence $occurrence) use ($ranges, $histories): UpcomingRecurringItem {
                $plan = $occurrence->plannedTransaction;
                $range = $occurrence->expected_amount === null ? ($ranges[$plan->id] ?? null) : null;

                return new UpcomingRecurringItem(
                    planId: $plan->id,
                    name: $plan->description,
                    dueOn: $occurrence->effectiveDueDate(),
                    expected: abs($occurrence->expected_amount ?? $histories[$plan->id] ?? $plan->amount),
                    low: $range['low'] ?? null,
                    high: $range['high'] ?? null,
                    income: $plan->direction === TransactionDirection::Credit,
                );
            })
            ->values()
            ->all();
    }

    /**
     * The low and high of what each plan's settled occurrences were paid, for plans where they differ.
     *
     * @param  list<int>  $planIds
     * @return array<int, array{low: int, high: int}>
     */
    private function paidRanges(array $planIds): array
    {
        $ranges = [];

        PlannedOccurrence::query()
            ->whereIn('planned_transaction_id', $planIds)
            ->where('status', OccurrenceStatus::Paid->value)
            ->withSum('payments', 'amount')
            ->get(['id', 'planned_transaction_id'])
            ->groupBy('planned_transaction_id')
            ->each(static function (Collection $paid, int $planId) use (&$ranges): void {
                $totals = $paid->map(static fn (PlannedOccurrence $occurrence): int => (int) $occurrence->payments_sum_amount)->filter(static fn (int $total): bool => $total > 0);

                if ($totals->count() > 0 && $totals->min() !== $totals->max()) {
                    $ranges[$planId] = ['low' => $totals->min(), 'high' => $totals->max()];
                }
            });

        return $ranges;
    }

    /** @return Collection<int, BillRow> */
    private function rows(User $user, CarbonImmutable $first, CarbonImmutable $last, CarbonImmutable $today): Collection
    {
        $from = $first->toDateString();
        $to = $last->toDateString();

        $occurrences = PlannedOccurrence::query()
            ->whereIn('status', [OccurrenceStatus::Scheduled->value, OccurrenceStatus::Paid->value, OccurrenceStatus::Missed->value])
            ->whereIn('planned_transaction_id', PlannedTransaction::query()->where('user_id', $user->id)->payable()->active()->select('id'))
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $unsnoozed): Builder => $unsnoozed->whereNull('snoozed_until')->whereDate('due_date', '>=', $from)->whereDate('due_date', '<=', $to))
                ->orWhere(fn (Builder $snoozed): Builder => $snoozed->whereDate('snoozed_until', '>=', $from)->whereDate('snoozed_until', '<=', $to)))
            ->withSum('payments', 'amount')
            ->with('plannedTransaction')
            ->get();

        $histories = $this->amounts->forPlans($occurrences->pluck('plannedTransaction')->unique('id')->values());

        return $occurrences
            ->sortBy(static fn (PlannedOccurrence $occurrence): string => $occurrence->effectiveDueDate()->format('Y-m-d').'|'.$occurrence->id)
            ->map(function (PlannedOccurrence $occurrence) use ($histories, $today): BillRow {
                $plan = $occurrence->plannedTransaction;
                $expected = abs($occurrence->expected_amount ?? $histories[$plan->id] ?? $plan->amount);
                $paid = (int) $occurrence->payments_sum_amount;

                return new BillRow($plan->id, $occurrence->id, $plan->description, $plan->type, $occurrence->effectiveDueDate(), $expected, $paid, max($expected - $paid, 0), $this->states->resolve($occurrence, $today, $paid, $expected), $plan->autopay);
            })
            ->values();
    }
}
