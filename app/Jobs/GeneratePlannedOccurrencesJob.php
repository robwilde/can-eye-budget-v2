<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\Recurring\PlannedOccurrenceGenerator;
use App\Services\Recurring\RecurringGate;
use App\Support\Audit\AttributeJobToUser;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Fills one user's stored plan occurrences (nothing while the user has recurring switched off). Queued when a plan is created or its schedule edited,
 * after a transaction analysis and every night, so it is upkeep and not audited as a user action.
 * A rebuild also removes the user's open occurrences from before the current cycle first.
 * Queued runs for the same user and rebuild flag collapse into one until it starts.
 */
final class GeneratePlannedOccurrencesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    private const int LOCK_SECONDS = 120;

    private const int OVERLAP_RELEASE_SECONDS = 10;

    public int $timeout = 90;

    public int $tries = 15;

    public int $maxExceptions = 1;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly User $user,
        public readonly bool $rebuildHistory = false,
    ) {}

    public function uniqueId(): string
    {
        return "planned-occurrences-{$this->user->id}-".($this->rebuildHistory ? 'rebuild' : 'fill');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            new AttributeJobToUser($this->user->id, audit: false),
            new WithoutOverlapping("planned-occurrences-{$this->user->id}")->expireAfter(self::LOCK_SECONDS)->releaseAfter(self::OVERLAP_RELEASE_SECONDS),
        ];
    }

    public function handle(PlannedOccurrenceGenerator $generator): void
    {
        if (! RecurringGate::enabled($this->user)) {
            return;
        }

        if ($this->rebuildHistory) {
            $generator->rebuildHistory($this->user);
        }

        $generator->generateForUser($this->user);
    }
}
