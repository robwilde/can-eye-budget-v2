<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\Investments\SecurityPriceSyncer;
use App\Support\Audit\AttributeJobToUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Daily price sync for the securities one user holds. Dispatched by securities:sync-prices, so it is
 * not audited as a user action.
 */
final class SyncSecurityPricesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Below the redis retry_after of 90 seconds, so a slow provider cannot make a second worker pick up
     * a copy that is still running; what a killed run did not reach, the next one picks up, because a
     * security refreshed today is skipped.
     */
    public int $timeout = 80;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300];

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

    public function handle(SecurityPriceSyncer $syncer): void
    {
        $syncer->sync($this->user);
    }
}
