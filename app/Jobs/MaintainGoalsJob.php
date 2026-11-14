<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\GoalManager;
use App\Services\GoalPledgeManager;
use App\Support\Audit\AttributeJobToUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Nightly upkeep of one user's goals: expires open pledges past their expiry and recalculates
 * months-of-expenses reserve targets. Scheduled by goals:maintain, so it is not audited as a
 * user action.
 */
final class MaintainGoalsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly User $user) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            new AttributeJobToUser($this->user->id, audit: false),
        ];
    }

    public function handle(GoalPledgeManager $pledges, GoalManager $goals): void
    {
        $pledges->expireDue($this->user);
        $goals->refreshReserveTargets($this->user);
    }
}
