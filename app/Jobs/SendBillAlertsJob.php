<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\Bills\BillAlertSender;
use App\Support\Audit\AttributeJobToUser;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * One user's daily bill alerts. It only reads and notifies that user, and is scheduled work rather than a user
 * action, so it is attributed to the user without being audited.
 */
final class SendBillAlertsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 5;

    public int $maxExceptions = 1;

    public int $uniqueFor = 600;

    public function __construct(public readonly User $user) {}

    public function uniqueId(): string
    {
        return "send-bill-alerts-{$this->user->id}";
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            new AttributeJobToUser($this->user->id, audit: false),
            new WithoutOverlapping("send-bill-alerts-{$this->user->id}")->expireAfter(180)->releaseAfter(15),
        ];
    }

    public function handle(BillAlertSender $alerts): void
    {
        if (! config('budget.bill_alerts')) {
            return;
        }

        $alerts->sendFor($this->user);
    }
}
