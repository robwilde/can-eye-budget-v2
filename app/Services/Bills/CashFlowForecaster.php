<?php

declare(strict_types=1);

namespace App\Services\Bills;

use App\DTOs\CashFlowForecast;
use App\Enums\AccountClass;
use App\Enums\OccurrenceStatus;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CurrencyConverter;
use App\Services\Recurring\PlanAmountResolver;
use App\Services\Recurring\PlannedOccurrenceGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * A deliberately cautious thirty day look at cash: what is in the user's tracked everyday and savings accounts,
 * less the remaining amount of every open bill that is not a transfer (each occurrence of a weekly bill counts, and
 * an overdue bill is due today), less typical daily spend. Income is not counted, so the warning errs on the early
 * side. Typical spend is the average of the user's countable debits over the last ninety days that did not pay a bill.
 */
final readonly class CashFlowForecaster
{
    public const int DAYS = 30;

    public const int SPEND_WINDOW_DAYS = 90;

    public function __construct(private PlanAmountResolver $amounts, private PlannedOccurrenceGenerator $generator) {}

    public function forecast(User $user, ?CarbonImmutable $today = null): ?CashFlowForecast
    {
        $today ??= $user->today();

        $accounts = Account::query()->where('user_id', $user->id)->active()->tracked()->whereIn('type', [AccountClass::Transaction->value, AccountClass::Savings->value])->get(['id', 'balance', 'currency']);

        if ($accounts->isEmpty()) {
            return null;
        }

        $cash = $this->cash($user, $accounts, $today);
        $dueByDay = $this->billsByDay($user, $today);
        $dailySpend = $this->dailySpend($user, $accounts->pluck('id')->all(), $today);
        $running = $cash;
        $low = $cash;
        $lowOn = $today;

        for ($day = 0; $day <= self::DAYS; $day++) {
            $running -= ($dueByDay[$day] ?? 0) + ($day === 0 ? 0 : $dailySpend);

            if ($running < $low) {
                $low = $running;
                $lowOn = $today->addDays($day);
            }
        }

        return new CashFlowForecast($cash, (int) array_sum($dueByDay), $dailySpend, $low, $lowOn);
    }

    /** @param  Collection<int, Account>  $accounts */
    private function cash(User $user, Collection $accounts, CarbonImmutable $today): int
    {
        if (! CurrencyConverter::enabled()) {
            return (int) $accounts->sum('balance');
        }

        $converter = app(CurrencyConverter::class);

        return (int) $accounts->sum(fn (Account $account): int => $converter->convert($account->balance, $account->currency, $user->base_currency, $today));
    }

    /** @return array<int, int> */
    private function billsByDay(User $user, CarbonImmutable $today): array
    {
        $floor = $this->generator->cycleStart($user, $today)->toDateString();

        $occurrences = PlannedOccurrence::query()
            ->where('status', OccurrenceStatus::Scheduled->value)
            ->whereIn('planned_transaction_id', PlannedTransaction::query()->where('user_id', $user->id)->payable()->active()->whereNull('transfer_to_account_id')->select('id'))
            ->where(fn ($query) => $query
                ->where(fn ($unsnoozed) => $unsnoozed->whereNull('snoozed_until')->whereDate('due_date', '>=', $floor)->whereDate('due_date', '<=', $today->addDays(self::DAYS)->toDateString()))
                ->orWhere(fn ($snoozed) => $snoozed->whereDate('snoozed_until', '>=', $floor)->whereDate('snoozed_until', '<=', $today->addDays(self::DAYS)->toDateString())))
            ->withSum('payments', 'amount')
            ->with('plannedTransaction')
            ->get();

        $histories = $this->amounts->forPlans($occurrences->pluck('plannedTransaction')->unique('id')->values());
        $byDay = [];

        foreach ($occurrences as $occurrence) {
            $plan = $occurrence->plannedTransaction;
            $remaining = max(abs($occurrence->expected_amount ?? $histories[$plan->id] ?? $plan->amount) - (int) $occurrence->payments_sum_amount, 0);
            $day = max((int) $today->diffInDays($occurrence->effectiveDueDate(), false), 0);

            $byDay[$day] = ($byDay[$day] ?? 0) + $remaining;
        }

        return $byDay;
    }

    /** @param  list<int>  $accountIds */
    private function dailySpend(User $user, array $accountIds, CarbonImmutable $today): int
    {
        $since = $today->subDays(self::SPEND_WINDOW_DAYS - 1);

        $query = Transaction::query()
            ->where('transactions.user_id', $user->id)
            ->whereIn('transactions.account_id', $accountIds)
            ->where('transactions.direction', TransactionDirection::Debit->value)
            ->whereDate('transactions.post_date', '>=', $since->toDateString())
            ->whereDate('transactions.post_date', '<=', $today->toDateString())
            ->current()
            ->countable()
            ->whereDoesntHave('payments');

        $total = (int) (clone $query)->sum('amount');
        $first = (clone $query)->min('transactions.post_date');

        if ($total === 0 || $first === null) {
            return 0;
        }

        $days = min(self::SPEND_WINDOW_DAYS, max((int) CarbonImmutable::parse((string) $first)->diffInDays($today) + 1, 7));

        return intdiv(abs($total), $days);
    }
}
