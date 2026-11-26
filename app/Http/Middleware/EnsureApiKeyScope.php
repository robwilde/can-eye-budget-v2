<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiKeyScope;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Methods that change data need a key with the read_write scope; a read-only key gets 403 and nothing runs.
 * A key without the write ability is refused even if its scope column says otherwise.
 */
final readonly class EnsureApiKeyScope
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $key = $request->user()?->currentAccessToken();

        if (! $key instanceof ApiKey || $key->scope !== ApiKeyScope::ReadWrite || ! $key->can(ApiKeyScope::ABILITY_WRITE)) {
            return new JsonResponse(['message' => 'insufficient scope'], 403);
        }

        return $next($request);
    }
}
