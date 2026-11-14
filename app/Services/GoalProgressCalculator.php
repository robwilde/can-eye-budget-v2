<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\GoalProgressDto;
use App\Enums\AccountClass;
use App\Enums\GoalHealth;
use App\Enums\GoalKind;
use App\Enums\GoalStatus;
use App\Enums\TransactionDirection;
use App\Enums\TransactionStatus;
use App\Enums\ValuationKind;
use App\Models\Account;
use App\Models\AccountDailyBalance;
use App\Models\AccountValuation;
use App\Models\Goal;
use App\Models\GoalAccount;
use App\Models\GoalSpend;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reads a goal's progress from the live balances of its linked accounts; there is no
 * contribution ledger. Earmarks are honoured, an over-earmarked account is scaled down
 * pro rata and an overdrawn account backs nothing. Every query is limited to the goal
 * owner's rows. calculateMany() computes a whole list of one user's goals with a fixed
 * number of queries, however many goals there are.
 *
 * @phpstan-type GoalContext array{userIds: array<int, int>, accountIds: array<int, int>, links: Collection<int|string, \Illuminate\Database\Eloquent\Collection<int, GoalAccount>>, balances: Collection<int, int>, holds: Collection<int, GoalAccount>, spends: Collection<int|string, int>}
 */
final class GoalProgressCalculator
{
    private const int PACE_WINDOW_DAYS = 90;

    private const int PACE_WINDOW_MONTHS = 3;

    private const int DAYS_PER_MONTH = 30;

    private const int PERCENT_CAP = 99;

    public function calculate(Goal $goal, ?CarbonImmutable $today = null): GoalProgressDto
    {
        return $this->calculateMany(new Collection([$goal]), $today)[$goal->id];
    }

    /**
     * Progress for each of one user's goals, by goal id, from one set of queries for links,
     * balances, earmark holds, spends and pace.
     *
     * @param  Collection<int, Goal>  $goals
     * @return array<int, GoalProgressDto>
     */
    public function calculateMany(Collection $goals, ?CarbonImmutable $today = null): array
    {
        if ($goals->isEmpty()) {
            return [];
        }

        $today ??= $goals->first()->user->today();
        $context = $this->context($goals);
        $nets = $this->netsByAccount($context['userIds'], $context['accountIds'], $today);
        $progress = [];

        foreach ($goals as $goal) {
            $saved = $this->savedFrom($goal, $context);
            $remaining = max(0, $goal->target_amount - $saved);
            $net = 0;

            foreach ($context['links']->get($goal->id, new Collection) as $link) {
                $net += $nets[$link->account_id] ?? 0;
            }

            $pace = intdiv($net, self::PACE_WINDOW_MONTHS);
            $days = $this->daysToTarget($goal, $today);

            $progress[$goal->id] = new GoalProgressDto(
                savedAmount: $saved,
                targetAmount: $goal->target_amount,
                remainingAmount: $remaining,
                percent: $this->percent($saved, $goal->target_amount, $remaining),
                pacePerMonth: $pace,
                monthlyTarget: $this->monthlyTarget($remaining, $days),
                daysToTarget: $days,
                health: $this->health($goal, $saved, $remaining, $pace, $days),
            );
        }

        return $progress;
    }

    public function savedAmount(Goal $goal): int
    {
        return $this->savedFrom($goal, $this->context(new Collection([$goal])));
    }

    /**
     * What each linked account currently contributes to the goal, in cents, by account id.
     *
     * @return array<int, int>
     */
    public function accountBackings(Goal $goal): array
    {
        return $this->currentBackings($goal, $this->context(new Collection([$goal])));
    }

    /**
     * What each account backs a goal with, in cents by account id, from balances the caller
     * supplies. Pure: no queries, so a caller can run it once per day over a balance history.
     *
     * An overdrawn account backs nothing. An earmarked account backs its earmark, scaled down
     * pro rata with the earmarks other goals hold on it when those together exceed its
     * balance. A whole-balance account (earmark null) backs the balance left after the other
     * goals' earmarks. An account missing from $balancesByAccountId is left out.
     *
     * @param  array<int, int|null>  $earmarks  this goal's earmark by account id, null for the whole balance
     * @param  array<int, int>  $balancesByAccountId  each account's counted balance in cents
     * @param  array<int, int>  $heldByOthers  cents other active or paused goals earmark on each account; a missing account holds none
     * @return array<int, int>
     */
    public function backingsFor(array $earmarks, array $balancesByAccountId, array $heldByOthers = []): array
    {
        $backings = [];

        foreach ($earmarks as $accountId => $earmark) {
            if (! array_key_exists($accountId, $balancesByAccountId)) {
                continue;
            }

            $backing = max(0, $balancesByAccountId[$accountId]);
            $othersHeld = $heldByOthers[$accountId] ?? 0;

            $backings[$accountId] = $earmark === null
                ? max(0, $backing - $othersHeld)
                : $this->scaledEarmark($earmark, $othersHeld, $backing);
        }

        return $backings;
    }

    /**
     * The goal's earmarks by account id (null = whole balance), loaded for backingsFor().
     *
     * @return array<int, int|null>
     */
    public function earmarksOf(Goal $goal): array
    {
        return GoalAccount::query()
            ->where('goal_id', $goal->id)
            ->orderBy('id')
            ->pluck('earmark_amount', 'account_id')
            ->all();
    }

    /**
     * What other active or paused goals of the same user earmark on each of the goal's
     * accounts, by account id, loaded once for backingsFor().
     *
     * @return array<int, int>
     */
    public function heldByOthersOf(Goal $goal): array
    {
        $accountIds = GoalAccount::query()->where('goal_id', $goal->id)->pluck('account_id')->all();

        return $this->heldBy($this->holds([$goal->user_id], $accountIds), $goal->id);
    }

    /**
     * @param  Collection<int, Goal>  $goals
     * @return GoalContext
     */
    private function context(Collection $goals): array
    {
        $userIds = $goals->map(fn (Goal $goal): int => $goal->user_id)->unique()->values()->all();
        $links = GoalAccount::query()
            ->whereIn('goal_id', $goals->pluck('id')->all())
            ->orderBy('id')
            ->get()
            ->groupBy('goal_id');
        $accountIds = $links->flatten(1)->map(fn (GoalAccount $link): int => $link->account_id)->unique()->values()->all();
        $oneOffIds = $goals->filter(fn (Goal $goal): bool => $goal->kind === GoalKind::OneOff)->pluck('id')->all();

        return [
            'userIds' => $userIds,
            'accountIds' => $accountIds,
            'links' => $links,
            'balances' => $this->countedBalances($userIds, $accountIds),
            'holds' => $this->holds($userIds, $accountIds),
            'spends' => GoalSpend::query()
                ->whereIn('goal_id', $oneOffIds)
                ->get(['goal_id', 'amount'])
                ->groupBy('goal_id')
                ->map(fn (Collection $rows): int => (int) $rows->sum('amount')),
        ];
    }

    /**
     * @param  GoalContext  $context
     */
    private function savedFrom(Goal $goal, array $context): int
    {
        if ($goal->frozen_saved_amount !== null) {
            return $goal->frozen_saved_amount;
        }

        return array_sum($this->currentBackings($goal, $context)) + (int) $context['spends']->get($goal->id, 0);
    }

    /**
     * @param  GoalContext  $context
     * @return array<int, int>
     */
    private function currentBackings(Goal $goal, array $context): array
    {
        $earmarks = [];

        foreach ($context['links']->get($goal->id, new Collection) as $link) {
            $earmarks[$link->account_id] = $link->earmark_amount;
        }

        return $this->backingsFor($earmarks, $context['balances']->all(), $this->heldBy($context['holds'], $goal->id));
    }

    /**
     * @param  Collection<int, GoalAccount>  $holds
     * @return array<int, int>
     */
    private function heldBy(Collection $holds, int $exceptGoalId): array
    {
        return $holds
            ->where('goal_id', '!=', $exceptGoalId)
            ->groupBy('account_id')
            ->map(fn (Collection $rows): int => (int) $rows->sum('earmark_amount'))
            ->all();
    }

    /**
     * Earmarks of active and paused goals of the given users on the given accounts.
     *
     * @param  array<int, int>  $userIds
     * @param  array<int, int>  $accountIds
     * @return Collection<int, GoalAccount>
     */
    private function holds(array $userIds, array $accountIds): Collection
    {
        return GoalAccount::query()
            ->whereIn('account_id', $accountIds)
            ->whereNotNull('earmark_amount')
            ->whereHas('goal', fn ($query) => $query
                ->whereIn('user_id', $userIds)
                ->whereIn('status', [GoalStatus::Active->value, GoalStatus::Paused->value]))
            ->get(['goal_id', 'account_id', 'earmark_amount']);
    }

    private function scaledEarmark(int $earmark, int $othersHeld, int $backing): int
    {
        $total = $othersHeld + $earmark;

        if ($total <= $backing) {
            return $earmark;
        }

        return intdiv($earmark * $backing, $total);
    }

    /**
     * Each account's balance as it counts toward goals, by account id. Investment accounts
     * count contributions, not market gains: the opening balance plus inflows minus outflows
     * of the daily balance history, capped at the stored balance so a loss still shows. An
     * investment account with no history counts its stored balance.
     *
     * @param  array<int, int>  $userIds
     * @param  array<int, int>  $accountIds
     * @return Collection<int, int>
     */
    private function countedBalances(array $userIds, array $accountIds): Collection
    {
        $accounts = Account::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('id', $accountIds)
            ->get(['id', 'type', 'balance']);

        $investmentIds = $accounts->where('type', AccountClass::Investment)->pluck('id')->all();
        $openings = $investmentIds === []
            ? collect()
            : AccountValuation::query()
                ->whereIn('account_id', $investmentIds)
                ->where('kind', ValuationKind::OpeningBalance)
                ->pluck('balance', 'account_id');

        $contributions = $investmentIds === []
            ? collect()
            : AccountDailyBalance::query()
                ->whereIn('account_id', $investmentIds)
                ->orderBy('balance_date')
                ->get(['account_id', 'start_balance', 'inflows', 'outflows'])
                ->groupBy('account_id')
                ->map(fn (Collection $rows, int $accountId): int => (int) ($openings->get($accountId) ?? $rows->first()->start_balance) + $rows->sum('inflows') - $rows->sum('outflows'));

        return $accounts->mapWithKeys(fn (Account $account): array => [
            $account->id => $contributions->has($account->id) ? min($account->balance, (int) $contributions->get($account->id)) : $account->balance,
        ]);
    }

    /**
     * Net inflow per account over the 90 days ending today, today included: the half-open
     * interval from the start of the day 89 days back up to the start of tomorrow. Credits add
     * and debits subtract by magnitude, whatever sign the amount is stored with.
     *
     * @param  array<int, int>  $userIds
     * @param  array<int, int>  $accountIds
     * @return array<int, int>
     */
    private function netsByAccount(array $userIds, array $accountIds, CarbonImmutable $today): array
    {
        if ($accountIds === []) {
            return [];
        }

        $nets = [];

        Transaction::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('account_id', $accountIds)
            ->current()
            ->where('excluded', false)
            ->where('status', TransactionStatus::Posted)
            ->where('post_date', '>=', $today->subDays(self::PACE_WINDOW_DAYS - 1)->startOfDay())
            ->where('post_date', '<', $today->addDay()->startOfDay())
            ->get(['account_id', 'direction', 'amount'])
            ->each(function (Transaction $transaction) use (&$nets): void {
                $nets[$transaction->account_id] = ($nets[$transaction->account_id] ?? 0)
                    + ($transaction->direction === TransactionDirection::Credit ? 1 : -1) * abs($transaction->amount);
            });

        return $nets;
    }

    private function daysToTarget(Goal $goal, CarbonImmutable $today): ?int
    {
        if ($goal->kind === GoalKind::Reserve || $goal->target_date === null) {
            return null;
        }

        return (int) $today->startOfDay()->diffInDays($goal->target_date->startOfDay(), false);
    }

    private function percent(int $saved, int $target, int $remaining): int
    {
        if ($remaining <= 0) {
            return 100;
        }

        return min(self::PERCENT_CAP, intdiv(max(0, $saved) * 100, $target));
    }

    private function monthlyTarget(int $remaining, ?int $days): ?int
    {
        if ($days === null) {
            return null;
        }

        if ($days <= 0) {
            return $remaining;
        }

        return intdiv($remaining * self::DAYS_PER_MONTH + $days - 1, $days);
    }

    private function health(Goal $goal, int $saved, int $remaining, int $pace, ?int $days): GoalHealth
    {
        if ($goal->kind === GoalKind::Reserve) {
            return $saved >= $goal->target_amount ? GoalHealth::Funded : GoalHealth::Depleted;
        }

        if ($remaining <= 0 || $goal->status === GoalStatus::Completed) {
            return GoalHealth::Reached;
        }

        if ($days === null) {
            return GoalHealth::NoTargetDate;
        }

        if ($goal->status === GoalStatus::Paused) {
            return GoalHealth::OnTrack;
        }

        return $days > 0 && $pace * $days >= $remaining * self::DAYS_PER_MONTH
            ? GoalHealth::OnTrack
            : GoalHealth::Behind;
    }
}
