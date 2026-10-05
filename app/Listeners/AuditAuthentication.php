<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditOutcome;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\SentryActor;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

final readonly class AuditAuthentication
{
    public function __construct(
        private AuditRecorder $recorder,
        private SentryActor $sentryActor,
    ) {}

    public function handle(Login|Logout|Failed|Registered|PasswordReset|Verified|TwoFactorAuthenticationFailed $event): void
    {
        $actorId = $this->id($event->user);

        if ($event instanceof Login) {
            $this->sentryActor->bind($actorId);
        }

        $this->recorder->recordSafely(
            action: match (true) {
                $event instanceof Logout => 'auth.logout',
                $event instanceof Registered => 'auth.register',
                $event instanceof PasswordReset => 'auth.password_reset',
                $event instanceof Verified => 'auth.email_verified',
                default => 'auth.login',
            },
            outcome: $event instanceof Failed || $event instanceof TwoFactorAuthenticationFailed
                ? AuditOutcome::Failure
                : AuditOutcome::Success,
            actorId: $actorId,
        );
    }

    private function id(?Authenticatable $user): ?int
    {
        $id = $user?->getAuthIdentifier();

        return $id === null ? null : (int) $id;
    }
}
