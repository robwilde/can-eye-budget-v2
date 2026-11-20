<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountClass;
use App\Enums\GoalKind;
use App\Enums\GoalStatus;
use App\Enums\TransactionDirection;
use App\Exceptions\GoalRefusedException;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalAccount;
use App\Models\GoalSpend;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Creates, edits and moves goals through their lifecycle, and records spends against them.
 * Every action runs in one transaction that locks the user's goals in id order before it
 * reads or writes, and every query is limited to the acting user's rows (accounts and
 * transactions directly, goal links and spends through their goal).
 */
final class GoalManager
{
    public function __construct(
        private readonly GoalProgressCalculator $calculator,
        private readonly MedianMonthlySpend $medianMonthlySpend,
    ) {}

    /**
     * @param  array{name: string, kind?: GoalKind, target_amount?: int, target_date?: string|null, target_months_of_expenses?: int|null, colour?: string|null, icon?: string|null, notes?: string|null, accounts: list<array{account_id: int, earmark_amount?: int|null}>}  $data
     *
     * @throws GoalRefusedException|Throwable
     */
    public function create(User $user, array $data): Goal
    {
        return Goal::resolveConnection()->transaction(function () use ($user, $data): Goal {
            $this->lockGoals($user);

            return $this->save($user, new Goal(['user_id' => $user->id, 'status' => GoalStatus::Active]), $data);
        });
    }

    /**
     * @param  array{name: string, kind?: GoalKind, target_amount?: int, target_date?: string|null, target_months_of_expenses?: int|null, colour?: string|null, icon?: string|null, notes?: string|null, accounts: list<array{account_id: int, earmark_amount?: int|null}>}  $data
     *
     * @throws GoalRefusedException|Throwable
     */
    public function update(User $user, Goal $goal, array $data): Goal
    {
        return Goal::resolveConnection()->transaction(function () use ($user, $goal, $data): Goal {
            $goals = $this->lockGoals($user);

            return $this->save($user, $this->owned($goals, $goal), $data);
        });
    }

    /**
     * @throws GoalRefusedException|Throwable
     */
    public function pause(User $user, Goal $goal): Goal
    {
        return $this->transition($user, $goal, function (Goal $locked): void {
            $this->requireStatus($locked, [GoalStatus::Active], __('Only an active goal can be paused.'));
            $locked->update(['status' => GoalStatus::Paused]);
        });
    }

    /**
     * @throws GoalRefusedException|Throwable
     */
    public function resume(User $user, Goal $goal): Goal
    {
        return $this->transition($user, $goal, function (Goal $locked): void {
            $this->requireStatus($locked, [GoalStatus::Paused], __('Only a paused goal can be resumed.'));
            $locked->update(['status' => GoalStatus::Active]);
        });
    }

    /**
     * @throws GoalRefusedException|Throwable
     */
    public function complete(User $user, Goal $goal): Goal
    {
        return $this->transition($user, $goal, function (Goal $locked): void {
            if ($locked->kind === GoalKind::Reserve) {
                throw new GoalRefusedException(__('A maintained reserve cannot be completed.'));
            }

            $this->requireStatus($locked, [GoalStatus::Active, GoalStatus::Paused], __('Only an active or paused goal can be completed.'));

            $locked->update([
                'frozen_saved_amount' => $this->calculator->savedAmount($locked),
                'completed_at' => now(),
                'status' => GoalStatus::Completed,
            ]);
        });
    }

    /**
     * @throws GoalRefusedException|Throwable
     */
    public function reopen(User $user, Goal $goal): Goal
    {
        return $this->transition($user, $goal, function (Goal $locked): void {
            $this->requireStatus($locked, [GoalStatus::Completed], __('Only a completed goal can be reopened.'));
            $this->thaw($locked);
        });
    }

    /**
     * @throws GoalRefusedException|Throwable
     */
    public function archive(User $user, Goal $goal): Goal
    {
        return $this->transition($user, $goal, function (Goal $locked): void {
            $this->requireStatus($locked, [GoalStatus::Active, GoalStatus::Paused, GoalStatus::Completed], __('That goal is already archived.'));
            $locked->update(['archived_at' => now(), 'status' => GoalStatus::Archived]);
        });
    }

    /**
     * @throws GoalRefusedException|Throwable
     */
    public function unarchive(User $user, Goal $goal): Goal
    {
        return $this->transition($user, $goal, function (Goal $locked): void {
            $this->requireStatus($locked, [GoalStatus::Archived], __('Only an archived goal can be unarchived.'));
            $this->thaw($locked);
        });
    }

    /**
     * @throws GoalRefusedException|Throwable
     */
    public function delete(User $user, Goal $goal): void
    {
        Goal::resolveConnection()->transaction(function () use ($user, $goal): void {
            $this->owned($this->lockGoals($user), $goal)->delete();
        });
    }

    /**
     * Recomputes every months-of-expenses reserve target of the user from the current median
     * monthly spend and returns how many targets changed. Completed and archived goals are
     * left alone.
     *
     * @throws Throwable
     */
    public function refreshReserveTargets(User $user): int
    {
        return Goal::resolveConnection()->transaction(function () use ($user): int {
            $reserves = $this->lockGoals($user)->filter(fn (Goal $goal): bool => $goal->kind === GoalKind::Reserve
                && $goal->target_months_of_expenses !== null
                && $this->holdsEarmarks($goal));

            if ($reserves->isEmpty()) {
                return 0;
            }

            $median = $this->medianMonthlySpend->forUser($user);
            $changed = 0;

            foreach ($reserves as $reserve) {
                $target = $reserve->target_months_of_expenses * $median;

                if ($reserve->target_amount !== $target) {
                    $reserve->update(['target_amount' => $target]);
                    $changed++;
                }
            }

            return $changed;
        });
    }

    /**
     * Records money spent from one of the goal's accounts. The account's earmark shrinks by
     * the same amount and the spend still counts toward the goal's progress.
     *
     * @throws GoalRefusedException|Throwable
     */
    public function recordSpend(User $user, Goal $goal, int $amount, ?Account $account = null, ?Transaction $transaction = null): GoalSpend
    {
        return Goal::resolveConnection()->transaction(function () use ($user, $goal, $amount, $account, $transaction): GoalSpend {
            $locked = $this->owned($this->lockGoals($user), $goal);

            if ($amount <= 0) {
                throw new GoalRefusedException(__('Enter a spend greater than zero.'));
            }

            if ($locked->kind === GoalKind::Reserve || $locked->status !== GoalStatus::Active) {
                throw new GoalRefusedException(__('Spends can only be recorded against an active one-off goal.'));
            }

            Account::query()->where('user_id', $user->id)->orderBy('id')->lockForUpdate()->pluck('id');
            $links = GoalAccount::query()->where('goal_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            $link = $this->spendLink($links, $account);
            $this->assertSpendTransaction($user, $transaction, $link->account_id);

            $alreadySpent = (int) GoalSpend::query()->where('goal_id', $locked->id)->sum('amount');

            if ($alreadySpent + $amount > $locked->target_amount) {
                throw new GoalRefusedException(__('Spends cannot add up to more than the goal target.'));
            }

            if ($amount > ($this->calculator->accountBackings($locked)[$link->account_id] ?? 0)) {
                throw new GoalRefusedException(__('That is more than this goal has set aside in the account.'));
            }

            if ($link->earmark_amount !== null) {
                $link->update(['earmark_amount' => $link->earmark_amount - $amount]);
            }

            return GoalSpend::query()->create([
                'goal_id' => $locked->id,
                'account_id' => $link->account_id,
                'transaction_id' => $transaction?->id,
                'amount' => $amount,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(User $user, Goal $goal, array $data): Goal
    {
        $accounts = $this->normaliseAccounts($user, $data['accounts']);
        $kind = $data['kind'] ?? GoalKind::OneOff;
        $months = $kind === GoalKind::Reserve ? ($data['target_months_of_expenses'] ?? null) : null;

        if ($goal->exists === false || $this->holdsEarmarks($goal)) {
            $this->assertNoWholeClaimClash($user, $goal, array_keys(array_filter($accounts, fn (?int $earmark): bool => $earmark === null)));
        }

        $goal->fill([
            'name' => $data['name'],
            'kind' => $kind,
            'target_amount' => $months === null ? ($data['target_amount'] ?? 0) : $months * $this->medianMonthlySpend->forUser($user),
            'target_date' => $kind === GoalKind::Reserve ? null : ($data['target_date'] ?? null),
            'target_months_of_expenses' => $months,
            'colour' => $data['colour'] ?? null,
            'icon' => $data['icon'] ?? null,
            'notes' => $data['notes'] ?? null,
        ])->save();

        GoalAccount::query()->where('goal_id', $goal->id)->whereNotIn('account_id', array_keys($accounts))->delete();

        foreach ($accounts as $accountId => $earmark) {
            GoalAccount::query()->updateOrCreate(
                ['goal_id' => $goal->id, 'account_id' => $accountId],
                ['earmark_amount' => $earmark],
            );
        }

        return $goal->refresh();
    }

    /**
     * @param  list<array{account_id: int, earmark_amount?: int|null}>  $accounts
     * @return array<int, int|null> earmark by account id, in id order
     */
    private function normaliseAccounts(User $user, array $accounts): array
    {
        $earmarks = [];

        foreach ($accounts as $entry) {
            $earmark = $entry['earmark_amount'] ?? null;

            if ($earmark !== null && $earmark < 0) {
                throw new GoalRefusedException(__('An earmark cannot be negative.'));
            }

            $earmarks[$entry['account_id']] = $earmark;
        }

        if ($earmarks === []) {
            throw new GoalRefusedException(__('Pick at least one account to fund this goal.'));
        }

        $owned = Account::query()
            ->where('user_id', $user->id)
            ->whereIn('id', array_keys($earmarks))
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'type', 'credit_limit', 'currency']);

        if ($owned->count() !== count($earmarks)) {
            throw new GoalRefusedException(__('Choose accounts that belong to you.'));
        }

        if ($owned->contains(fn (Account $account): bool => ! $this->canFundGoals($account))) {
            throw new GoalRefusedException(__('Choose cash or investment accounts to fund this goal.'));
        }

        if ($owned->pluck('currency')->unique()->count() > 1) {
            throw new GoalRefusedException(__('Choose accounts in one currency to fund this goal.'));
        }

        ksort($earmarks);

        return $earmarks;
    }

    private function canFundGoals(Account $account): bool
    {
        return ! $account->isLiability() && in_array($account->type, [
            AccountClass::Transaction,
            AccountClass::Savings,
            AccountClass::TermDeposit,
            AccountClass::Foreign,
            AccountClass::Investment,
        ], true);
    }

    /**
     * @param  list<int>  $wholeAccountIds
     */
    private function assertNoWholeClaimClash(User $user, Goal $goal, array $wholeAccountIds): void
    {
        if ($wholeAccountIds === []) {
            return;
        }

        $clash = GoalAccount::query()
            ->whereIn('account_id', $wholeAccountIds)
            ->whereNull('earmark_amount')
            ->when($goal->exists, fn ($query) => $query->where('goal_id', '!=', $goal->id))
            ->whereHas('goal', fn ($query) => $query
                ->where('user_id', $user->id)
                ->whereIn('status', [GoalStatus::Active->value, GoalStatus::Paused->value]))
            ->exists();

        if ($clash) {
            throw new GoalRefusedException(__('Another goal already claims the whole of that account.'));
        }
    }

    private function thaw(Goal $goal): void
    {
        $wholeAccountIds = GoalAccount::query()
            ->where('goal_id', $goal->id)
            ->whereNull('earmark_amount')
            ->pluck('account_id')
            ->all();

        $this->assertNoWholeClaimClash($goal->user, $goal, $wholeAccountIds);

        $goal->update([
            'status' => GoalStatus::Active,
            'frozen_saved_amount' => null,
            'completed_at' => null,
            'archived_at' => null,
        ]);
    }

    private function holdsEarmarks(Goal $goal): bool
    {
        return in_array($goal->status, [GoalStatus::Active, GoalStatus::Paused], true);
    }

    /**
     * @param  Collection<int, GoalAccount>  $links
     */
    private function spendLink(Collection $links, ?Account $account): GoalAccount
    {
        if ($account !== null) {
            return $links->firstWhere('account_id', $account->id)
                ?? throw new GoalRefusedException(__('That account does not fund this goal.'));
        }

        if ($links->count() !== 1) {
            throw new GoalRefusedException(__('Choose which account the spend came from.'));
        }

        return $links->first();
    }

    private function assertSpendTransaction(User $user, ?Transaction $transaction, int $accountId): void
    {
        if ($transaction === null) {
            return;
        }

        $owned = Transaction::query()
            ->current()
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->where('direction', TransactionDirection::Debit)
            ->whereKey($transaction->id)
            ->exists();

        if (! $owned) {
            throw new GoalRefusedException(__('Choose an outgoing transaction from the selected account.'));
        }
    }

    /**
     * @param  callable(Goal): void  $change
     */
    private function transition(User $user, Goal $goal, callable $change): Goal
    {
        return Goal::resolveConnection()->transaction(function () use ($user, $goal, $change): Goal {
            $locked = $this->owned($this->lockGoals($user), $goal);
            $change($locked);

            return $locked->refresh();
        });
    }

    /**
     * @param  list<GoalStatus>  $allowed
     */
    private function requireStatus(Goal $goal, array $allowed, string $message): void
    {
        if (! in_array($goal->status, $allowed, true)) {
            throw new GoalRefusedException($message);
        }
    }

    /**
     * @param  Collection<int, Goal>  $goals
     */
    private function owned(Collection $goals, Goal $goal): Goal
    {
        return $goals->get($goal->id) ?? throw new GoalRefusedException(__('That goal no longer exists.'));
    }

    /**
     * @return Collection<int, Goal>
     */
    private function lockGoals(User $user): Collection
    {
        return Goal::query()->where('user_id', $user->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }
}
