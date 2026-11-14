<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\GoalPledgeStatus;
use App\Exceptions\GoalRefusedException;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalAccount;
use App\Models\GoalPledge;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Opens, renews and cancels a user's pledges that money was just moved toward a goal, and
 * expires the ones left open too long. A pledge stays open for seven days. Every action runs
 * in one transaction, and every query is limited to the acting user's goals.
 */
final class GoalPledgeManager
{
    public const int OPEN_DAYS = 7;

    /**
     * @throws GoalRefusedException|Throwable
     */
    public function pledge(User $user, Goal $goal, Account $account, int $amount): GoalPledge
    {
        return GoalPledge::resolveConnection()->transaction(function () use ($user, $goal, $account, $amount): GoalPledge {
            $locked = Goal::query()->where('user_id', $user->id)->whereKey($goal->id)->lockForUpdate()->first()
                ?? throw new GoalRefusedException(__('That goal no longer exists.'));

            if ($amount <= 0) {
                throw new GoalRefusedException(__('Enter a pledge greater than zero.'));
            }

            $linked = GoalAccount::query()
                ->where('goal_id', $locked->id)
                ->where('account_id', $account->id)
                ->exists();

            if (! $linked) {
                throw new GoalRefusedException(__('That account does not fund this goal.'));
            }

            $duplicate = GoalPledge::query()
                ->where('goal_id', $locked->id)
                ->where('account_id', $account->id)
                ->where('amount', $amount)
                ->where('status', GoalPledgeStatus::Open)
                ->where('expires_at', '>', now())
                ->exists();

            if ($duplicate) {
                throw new GoalRefusedException(__('You already have an open pledge for that amount.'));
            }

            return GoalPledge::query()->create([
                'goal_id' => $locked->id,
                'account_id' => $account->id,
                'amount' => $amount,
                'status' => GoalPledgeStatus::Open,
                'expires_at' => now()->addDays(self::OPEN_DAYS),
            ]);
        });
    }

    /**
     * @throws GoalRefusedException|Throwable
     */
    public function renew(User $user, GoalPledge $pledge): GoalPledge
    {
        return GoalPledge::resolveConnection()->transaction(function () use ($user, $pledge): GoalPledge {
            $locked = $this->openPledge($user, $pledge, __('Only an open pledge can be renewed.'));

            if ($locked->expires_at->lessThanOrEqualTo(now())) {
                throw new GoalRefusedException(__('Only an open pledge can be renewed.'));
            }

            $locked->update(['expires_at' => $locked->expires_at->addDays(self::OPEN_DAYS)]);

            return $locked;
        });
    }

    /**
     * @throws GoalRefusedException|Throwable
     */
    public function cancel(User $user, GoalPledge $pledge): GoalPledge
    {
        return GoalPledge::resolveConnection()->transaction(function () use ($user, $pledge): GoalPledge {
            $locked = $this->openPledge($user, $pledge, __('Only an open pledge can be cancelled.'));
            $locked->update(['status' => GoalPledgeStatus::Cancelled]);

            return $locked;
        });
    }

    /**
     * Expires the user's open pledges whose expiry has passed and returns how many.
     *
     * @throws Throwable
     */
    public function expireDue(User $user): int
    {
        return GoalPledge::resolveConnection()->transaction(fn (): int => GoalPledge::query()
            ->whereIn('goal_id', $this->goalIds($user))
            ->where('status', GoalPledgeStatus::Open)
            ->where('expires_at', '<=', now())
            ->update(['status' => GoalPledgeStatus::Expired]));
    }

    private function openPledge(User $user, GoalPledge $pledge, string $refusal): GoalPledge
    {
        $locked = GoalPledge::query()
            ->whereIn('goal_id', $this->goalIds($user))
            ->whereKey($pledge->id)
            ->lockForUpdate()
            ->first() ?? throw new GoalRefusedException(__('That pledge no longer exists.'));

        if ($locked->status !== GoalPledgeStatus::Open) {
            throw new GoalRefusedException($refusal);
        }

        return $locked;
    }

    /**
     * @return Builder<Goal>
     */
    private function goalIds(User $user): Builder
    {
        return Goal::query()->where('user_id', $user->id)->select('id');
    }
}
