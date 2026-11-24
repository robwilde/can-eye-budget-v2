<?php

declare(strict_types=1);

namespace App\Services\Projection;

use App\DTOs\EffectiveOccurrence;
use App\DTOs\PaycheckPlan;
use App\DTOs\PayPeriod;
use App\Enums\PlannedTransactionType;
use App\Enums\PlanOrigin;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\CurrencyConverter;
use App\Services\Recurring\OccurrenceSchedule;
use App\Services\Recurring\PlannedOccurrenceGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Splits the coming weeks into pay periods and says which paycheck funds which bill. Paydays are the open
 * occurrences, today or later, of the user's active declared recurring income plans; same-day paychecks are one event.
 * Period 0 (today to the first payday) is measured against cash on hand. Each later period funds its own bills from
 * its paycheck; what is left over is reserved from earlier paychecks, nearest first, and whatever no earlier paycheck can
 * cover lands on the earliest paycheck as a shortfall, so it shows today rather than the week the bill falls due. A bill past due lands whole in the first period, a snoozed one is where it was snoozed to and
 * one snoozed past the plan leaves it. Amounts are what is still owed, in cents, as OccurrenceSchedule reports them.
 */
final readonly class PaycheckPlanner
{
    public const int DEFAULT_PERIODS = 3;

    public const int MAX_PERIODS = 6;

    public const int LAST_PERIOD_DAYS = 30;

    private const int PAYDAY_REACH_MONTHS = 8;

    public function __construct(private OccurrenceSchedule $schedule, private SpendableCash $cash, private PlannedOccurrenceGenerator $generator) {}

    /** @param  int  $paychecks  how many paychecks to plan, kept between one and six */
    public function plan(User $user, int $paychecks = self::DEFAULT_PERIODS, ?CarbonImmutable $today = null): PaycheckPlan
    {
        $paychecks = max(1, min(self::MAX_PERIODS, $paychecks));
        $today ??= $user->today();
        $incomePlans = $this->incomePlans($user);
        $converter = CurrencyConverter::enabled() ? app(CurrencyConverter::class) : null;
        $ignored = $this->ignored($incomePlans);
        $cash = $this->cash->for($user, $today)['total'] ?? null;

        $paydays = $this->paydays($incomePlans->filter(fn (PlannedTransaction $plan): bool => $this->isPayday($plan)), $today, $paychecks + 1, $user, $converter);

        if ($paydays === []) {
            return new PaycheckPlan([], true, $ignored, $cash);
        }

        $planned = array_slice($paydays, 0, $paychecks);
        $last = $planned[array_key_last($planned)]['date'];
        $end = isset($paydays[$paychecks]) ? $paydays[$paychecks]['date'] : $last->addDays(self::LAST_PERIOD_DAYS);

        $bills = $this->bills($user, $today, $end, $converter);
        $buckets = array_fill(0, $paychecks + 1, []);

        foreach ($bills as $bill) {
            $day = $bill['date']->lessThan($today) ? $today : $bill['date'];

            if ($day->greaterThanOrEqualTo($end)) {
                continue;
            }

            $index = 0;

            foreach ($planned as $position => $payday) {
                if ($payday['date']->lessThanOrEqualTo($day)) {
                    $index = $position + 1;
                }
            }

            $buckets[$index][] = $bill;
        }

        return new PaycheckPlan($this->periods($planned, $buckets, $today, $end, $cash), false, $ignored, $cash);
    }

    /**
     * Where each pay period of the current month starts, or nothing unless income splits the month into more than one
     * period: monthly pay on the 1st leaves one period, pay on the 15th or every fortnight leaves several. Paychecks
     * already received this month count, so the markers do not change as the month goes on.
     *
     * @return list<array{startsOn: CarbonImmutable, sources: list<string>}>
     */
    public function monthMarkers(User $user, ?CarbonImmutable $today = null): array
    {
        $today ??= $user->today();
        $start = $today->startOfMonth();
        $plans = $this->incomePlans($user)->filter(fn (PlannedTransaction $plan): bool => $this->isPayday($plan));

        if ($plans->isEmpty()) {
            return [];
        }

        $byDate = [];

        foreach ($this->schedule->between($plans, $start, $today->endOfMonth()->startOfDay(), includeSettled: true) as $planId => $occurrences) {
            foreach ($occurrences as $occurrence) {
                $byDate[$occurrence->date->format('Y-m-d')]['date'] = $occurrence->date;
                $byDate[$occurrence->date->format('Y-m-d')]['sources'][] = $plans[$planId]->description;
            }
        }

        if ($byDate === []) {
            return [];
        }

        ksort($byDate);

        $markers = array_map(static fn (array $event): array => ['startsOn' => $event['date'], 'sources' => array_values(array_unique($event['sources']))], array_values($byDate));

        if ($markers[0]['startsOn']->greaterThan($start)) {
            array_unshift($markers, ['startsOn' => $start, 'sources' => []]);
        }

        return count($markers) > 1 ? $markers : [];
    }

    /**
     * @param  list<array{date: CarbonImmutable, amount: int, sources: list<string>}>  $planned
     * @param  array<int, list<array{date: CarbonImmutable, name: string, amount: int}>>  $buckets
     * @return list<PayPeriod>
     */
    private function periods(array $planned, array $buckets, CarbonImmutable $today, CarbonImmutable $end, ?int $cash): array
    {
        $count = count($planned);
        $free = [];
        $fundedOwn = [];
        $reserved = array_fill(1, $count, 0);
        $fundedEarlier = array_fill(1, $count, 0);
        $uncovered = 0;

        for ($i = 1; $i <= $count; $i++) {
            $income = $planned[$i - 1]['amount'];
            $own = array_sum(array_column($buckets[$i], 'amount'));
            $fundedOwn[$i] = min($own, $income);
            $free[$i] = $income - $fundedOwn[$i];
            $overflow = $own - $fundedOwn[$i];

            for ($j = $i - 1; $j >= 1 && $overflow > 0; $j--) {
                $take = min($overflow, $free[$j]);
                $free[$j] -= $take;
                $reserved[$j] += $take;
                $fundedEarlier[$i] += $take;
                $overflow -= $take;
            }

            if ($overflow > 0 && $i > 1) {
                $reserved[1] += $overflow;
                $fundedEarlier[$i] += $overflow;
            } elseif ($overflow > 0) {
                $uncovered = $overflow;
            }
        }

        $periods = [];

        for ($i = $planned[0]['date']->greaterThan($today) ? 0 : 1; $i <= $count; $i++) {
            $bills = $buckets[$i];
            $billsDue = (int) array_sum(array_column($bills, 'amount'));
            $largest = collect($bills)->sortByDesc('amount')->first();
            $startsOn = $i === 0 ? $today : $planned[$i - 1]['date'];
            $endsOn = ($i === $count ? $end : $planned[$i]['date'])->subDay();

            if ($i === 0) {
                $periods[] = new PayPeriod(0, $startsOn, $endsOn, 0, [], $billsDue, 0, 0, $billsDue, $cash === null ? null : max($cash - $billsDue, 0), $cash === null ? 0 : max($billsDue - $cash, 0), $largest['name'] ?? null, $largest['amount'] ?? 0, count($bills));

                continue;
            }

            $income = $planned[$i - 1]['amount'];
            $obligation = $fundedOwn[$i] + $reserved[$i] + ($i === 1 ? $uncovered : 0);

            $periods[] = new PayPeriod($i, $startsOn, $endsOn, $income, $planned[$i - 1]['sources'], $billsDue, $fundedEarlier[$i], $reserved[$i], $obligation, max($income - $obligation, 0), max($obligation - $income, 0), $largest['name'] ?? null, $largest['amount'] ?? 0, count($bills));
        }

        return $periods;
    }

    /** @return Collection<int, PlannedTransaction> keyed by plan id */
    private function incomePlans(User $user): Collection
    {
        return PlannedTransaction::query()
            ->where('user_id', $user->id)
            ->visible()
            ->where('direction', TransactionDirection::Credit->value)
            ->where(fn ($query) => $query->where('type', PlannedTransactionType::Income->value)->orWhere('is_pay_cycle_income', true))
            ->with('account:id,currency')
            ->orderBy('id')
            ->get()
            ->keyBy('id');
    }

    private function isPayday(PlannedTransaction $plan): bool
    {
        return $plan->isActive() && $plan->origin === PlanOrigin::Declared && $plan->frequency !== RecurrenceFrequency::DontRepeat;
    }

    /**
     * @param  Collection<int, PlannedTransaction>  $incomePlans
     * @return list<array{planId: int, name: string, reason: string}>
     */
    private function ignored(Collection $incomePlans): array
    {
        return $incomePlans
            ->reject(fn (PlannedTransaction $plan): bool => $this->isPayday($plan))
            ->map(static fn (PlannedTransaction $plan): array => [
                'planId' => $plan->id,
                'name' => $plan->description,
                'reason' => ! $plan->isActive() ? __('Paused') : ($plan->origin !== PlanOrigin::Declared ? __('Detected only, not confirmed') : __('One-off income')),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, PlannedTransaction>  $plans
     * @return list<array{date: CarbonImmutable, amount: int, sources: list<string>}>
     */
    private function paydays(Collection $plans, CarbonImmutable $today, int $limit, User $user, ?CurrencyConverter $converter): array
    {
        if ($plans->isEmpty()) {
            return [];
        }

        $byDate = [];

        foreach ($this->schedule->between($plans, $today, $today->addMonthsNoOverflow(self::PAYDAY_REACH_MONTHS), openOnly: true) as $planId => $occurrences) {
            foreach ($occurrences as $occurrence) {
                $key = $occurrence->date->format('Y-m-d');
                $byDate[$key]['date'] = $occurrence->date;
                $byDate[$key]['amount'] = ($byDate[$key]['amount'] ?? 0) + $this->inBase(abs($occurrence->expected), $plans[$planId], $occurrence->date, $user, $converter);
                $byDate[$key]['sources'][] = $plans[$planId]->description;
            }
        }

        ksort($byDate);

        return array_slice(array_values(array_map(static fn (array $event): array => [...$event, 'sources' => array_values(array_unique($event['sources']))], $byDate)), 0, $limit);
    }

    /** @return list<array{date: CarbonImmutable, name: string, amount: int}> */
    private function bills(User $user, CarbonImmutable $today, CarbonImmutable $end, ?CurrencyConverter $converter): array
    {
        $plans = PlannedTransaction::query()->where('user_id', $user->id)->payable()->active()->with('account:id,currency')->orderBy('id')->get()->keyBy('id');

        if ($plans->isEmpty()) {
            return [];
        }

        $bills = [];
        $floor = $this->generator->cycleStart($user, $today);

        foreach ($this->schedule->between($plans, $floor, $end->subDay(), openOnly: true) as $planId => $occurrences) {
            foreach ($occurrences as $occurrence) {
                if ($occurrence->amount <= 0 || ($occurrence->date->lessThan($today) && ! $this->isStored($occurrence))) {
                    continue;
                }

                $bills[] = ['date' => $occurrence->date, 'name' => $plans[$planId]->description, 'amount' => $this->inBase($occurrence->amount, $plans[$planId], $occurrence->date, $user, $converter)];
            }
        }

        return $bills;
    }

    private function inBase(int $amount, PlannedTransaction $plan, CarbonImmutable $date, User $user, ?CurrencyConverter $converter): int
    {
        return $converter === null ? $amount : $converter->convert($amount, $plan->account->currency, $user->base_currency, $date);
    }

    private function isStored(EffectiveOccurrence $occurrence): bool
    {
        return $occurrence->stored !== null;
    }
}
