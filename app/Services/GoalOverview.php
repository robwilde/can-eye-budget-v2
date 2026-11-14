<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\GoalProgressDto;
use App\Enums\GoalHealth;
use App\Enums\GoalKind;
use App\Enums\GoalStatus;
use App\Enums\TransactionDirection;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalAccount;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The goals list for one user: every goal with its progress, ordered behind or depleted first,
 * then on track, open-ended, funded, reached and paused last, plus the summary strip. Every
 * query is limited to the user's rows.
 */
final readonly class GoalOverview
{
    private const int INFLOW_DAYS = 30;

    public function __construct(private GoalProgressCalculator $calculator) {}

    /**
     * @return array{rows: list<array{goal: Goal, progress: GoalProgressDto}>, summary: array{inflow: int, priorInflow: int, onTrack: int, behind: int, neededPerMonth: int}}
     */
    public function for(User $user): array
    {
        $today = $user->today();
        $rows = [];

        $goals = Goal::query()->where('user_id', $user->id)->orderBy('name')->orderBy('id')->get();
        $progress = $this->calculator->calculateMany($goals, $today);

        foreach ($goals as $goal) {
            $rows[] = ['goal' => $goal, 'progress' => $progress[$goal->id]];
        }

        usort($rows, fn (array $a, array $b): int => [$this->rank($a['goal'], $a['progress']), $a['goal']->name, $a['goal']->id]
            <=> [$this->rank($b['goal'], $b['progress']), $b['goal']->name, $b['goal']->id]);

        return ['rows' => $rows, 'summary' => $this->summary($user, $rows, $today)];
    }

    private function rank(Goal $goal, GoalProgressDto $progress): int
    {
        if ($goal->status === GoalStatus::Paused) {
            return 5;
        }

        return match ($progress->health) {
            GoalHealth::Behind, GoalHealth::Depleted => 0,
            GoalHealth::OnTrack => 1,
            GoalHealth::NoTargetDate => 2,
            GoalHealth::Funded => 3,
            GoalHealth::Reached => 4,
        };
    }

    /**
     * @param  list<array{goal: Goal, progress: GoalProgressDto}>  $rows
     * @return array{inflow: int, priorInflow: int, onTrack: int, behind: int, neededPerMonth: int}
     */
    private function summary(User $user, array $rows, CarbonImmutable $today): array
    {
        $active = array_filter($rows, static fn (array $row): bool => $row['goal']->status === GoalStatus::Active);
        $held = array_filter($rows, static fn (array $row): bool => in_array($row['goal']->status, [GoalStatus::Active, GoalStatus::Paused], true));

        $accountIds = $held === [] ? [] : GoalAccount::query()
            ->whereIn('goal_id', array_map(static fn (array $row): int => $row['goal']->id, $held))
            ->whereHas('account', fn ($query) => $query->where('user_id', $user->id))
            ->pluck('account_id')
            ->unique()
            ->all();

        return [
            'inflow' => $this->inflow($user, $accountIds, $today->subDays(self::INFLOW_DAYS - 1), $today),
            'priorInflow' => $this->inflow($user, $accountIds, $today->subDays(self::INFLOW_DAYS * 2 - 1), $today->subDays(self::INFLOW_DAYS)),
            'onTrack' => count(array_filter($active, static fn (array $row): bool => in_array($row['progress']->health, [GoalHealth::OnTrack, GoalHealth::Funded], true))),
            'behind' => count(array_filter($active, static fn (array $row): bool => in_array($row['progress']->health, [GoalHealth::Behind, GoalHealth::Depleted], true))),
            'neededPerMonth' => $this->neededPerMonth($user, $active, $today),
        ];
    }

    /**
     * @param  array<int, array{goal: Goal, progress: GoalProgressDto}>  $active
     */
    private function neededPerMonth(User $user, array $active, CarbonImmutable $today): int
    {
        $converter = CurrencyConverter::enabled() ? app(CurrencyConverter::class) : null;
        $currencies = $converter === null ? [] : $this->goalCurrencies($user, array_map(static fn (array $row): int => $row['goal']->id, $active));
        $needed = 0;

        foreach ($active as $row) {
            if ($row['goal']->kind !== GoalKind::OneOff || $row['progress']->health === GoalHealth::Reached) {
                continue;
            }

            $monthly = $row['progress']->monthlyTarget ?? 0;

            $needed += $converter === null
                ? $monthly
                : $converter->convert($monthly, $currencies[$row['goal']->id] ?? $user->base_currency, $user->base_currency, $today);
        }

        return $needed;
    }

    /**
     * @param  list<int>  $accountIds
     */
    private function inflow(User $user, array $accountIds, CarbonImmutable $from, CarbonImmutable $to): int
    {
        if ($accountIds === []) {
            return 0;
        }

        $currencies = Account::query()->where('user_id', $user->id)->whereIn('id', $accountIds)->pluck('currency', 'id');
        $converter = CurrencyConverter::enabled() ? app(CurrencyConverter::class) : null;

        return Transaction::query()
            ->where('user_id', $user->id)
            ->whereIn('account_id', $accountIds)
            ->current()
            ->where('excluded', false)
            ->where('status', TransactionStatus::Posted)
            ->where('direction', TransactionDirection::Credit)
            ->where('post_date', '>=', $from->startOfDay())
            ->where('post_date', '<=', $to->endOfDay())
            ->get(['account_id', 'amount', 'post_date'])
            ->sum(fn (Transaction $credit): int => $converter === null
                ? abs($credit->amount)
                : $converter->convert(abs($credit->amount), (string) $currencies->get($credit->account_id, $user->base_currency), $user->base_currency, $credit->post_date));
    }

    /**
     * The currency each goal is held in: that of its first linked account.
     *
     * @param  list<int>  $goalIds
     * @return array<int, string>
     */
    private function goalCurrencies(User $user, array $goalIds): array
    {
        $currencies = [];

        foreach (GoalAccount::query()->whereIn('goal_id', $goalIds)->with('account:id,user_id,currency')->orderBy('id')->get() as $link) {
            if ($link->account->user_id === $user->id) {
                $currencies[$link->goal_id] ??= $link->account->currency;
            }
        }

        return $currencies;
    }
}
