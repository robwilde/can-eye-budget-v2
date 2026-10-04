<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AuditOutcome;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\SentryActor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class AttributeRequestToUser
{
    public function __construct(
        private AuditRecorder $recorder,
        private SentryActor $sentryActor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        Context::add('request_id', (string) Str::uuid());

        $actorId = Auth::id();
        $this->sentryActor->bind($actorId === null ? null : (int) $actorId);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->audit($request, $actorId, AuditOutcome::Failure);

            throw $exception;
        }

        $this->audit(
            $request,
            $actorId,
            $this->succeeded($request, $response) ? AuditOutcome::Success : AuditOutcome::Failure,
        );

        return $response;
    }

    private function succeeded(Request $request, Response $response): bool
    {
        if ($response->getStatusCode() >= 400) {
            return false;
        }

        return ! ($request->hasSession() && in_array('errors', $request->session()->get('_flash.new', []), true));
    }

    private function audit(Request $request, int|string|null $actorId, AuditOutcome $outcome): void
    {
        if ($actorId === null || ! $this->isAuditable($request)) {
            return;
        }

        $this->recorder->recordSafely(
            action: 'http.'.$this->routeName($request),
            outcome: $outcome,
            actorId: (int) $actorId,
        );
    }

    private function isAuditable(Request $request): bool
    {
        return ! $request->isMethodSafe() && ! $request->routeIs('*livewire.update');
    }

    private function routeName(Request $request): string
    {
        $name = $request->route()?->getName();

        return is_string($name) && AuditRecorder::accepts('http.'.$name)
            ? $name
            : mb_strtolower($request->method());
    }
}
