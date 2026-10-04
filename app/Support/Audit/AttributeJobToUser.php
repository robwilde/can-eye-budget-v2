<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Enums\AuditOutcome;
use Closure;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Throwable;

final readonly class AttributeJobToUser
{
    public function __construct(private int $userId, private bool $audit = true) {}

    public function handle(object $job, Closure $next): void
    {
        $sentryActor = app(SentryActor::class);
        $recorder = app(AuditRecorder::class);
        $action = 'job.'.class_basename($job);

        $sentryActor->bind($this->userId);

        try {
            $next($job);
        } catch (Throwable $exception) {
            if ($this->audit) {
                $recorder->recordSafely($action, outcome: AuditOutcome::Failure, actorId: $this->userId);
            }

            throw $exception;
        }

        try {
            if ($this->audit && ! $this->released($job)) {
                $recorder->recordSafely($action, actorId: $this->userId);
            }
        } finally {
            $sentryActor->bind(null);
        }
    }

    private function released(object $job): bool
    {
        $queueJob = $job->job ?? null;

        return $queueJob instanceof QueueJob && $queueJob->isReleased();
    }
}
