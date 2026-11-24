<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\Recurring\PriceChangeDetector;
use App\Services\Recurring\StalePlanSweeper;
use App\Support\Audit\AttributeJobToUser;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * One user's nightly upkeep: record price changes from consistent payments, then retire stale plans. It only
 * touches the user's own plans and is not audited as a user action.
 */
final class RecurringMaintenanceJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 10;

    public int $maxExceptions = 1;

    public int $uniqueFor = 600;

    public function __construct(public readonly User $user) {}

    public function uniqueId(): string
    {
        return "recurring-maintenance-{$this->user->id}";
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            new AttributeJobToUser($this->user->id, audit: false),
            new WithoutOverlapping("recurring-maintenance-{$this->user->id}")->expireAfter(180)->releaseAfter(15),
        ];
    }

    public function handle(PriceChangeDetector $prices, StalePlanSweeper $sweeper): void
    {
        $prices->detectForUser($this->user);
        $sweeper->sweepForUser($this->user);
    }
}
