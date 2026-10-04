<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Enums\AuditOutcome;
use Closure;
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
                $recorder->record($action, outcome: AuditOutcome::Failure, actorId: $this->userId);
            }

            throw $exception;
        } finally {
            $sentryActor->bind(null);
        }

        if ($this->audit) {
            $recorder->record($action, actorId: $this->userId);
        }
    }
}
