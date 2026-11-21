<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\RuleDigestSender;
use App\Support\Audit\AttributeJobToUser;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emails the owner of each active "Email digest" rule the transactions that newly matched it.
 * Queued after the user's rules have run.
 */
final class SendRuleMatchDigestJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    private const int OVERLAP_TRIES = 30;

    private const int OVERLAP_RELEASE_SECONDS = 30;

    public int $tries = self::OVERLAP_TRIES;

    public int $maxExceptions = 1;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function __construct(public readonly User $user) {}

    public function uniqueId(): int
    {
        return $this->user->id;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            new AttributeJobToUser($this->user->id),
            new WithoutOverlapping("rule-digest-user-{$this->user->id}")->expireAfter(300)->releaseAfter(self::OVERLAP_RELEASE_SECONDS),
        ];
    }

    public function handle(RuleDigestSender $sender): void
    {
        $sender->send($this->user);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SendRuleMatchDigestJob failed', [
            'userId' => $this->user->id,
            'exception' => $exception,
        ]);
    }
}
