<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits API requests per client address before any authentication, so a flood of bad tokens is
 * counted. It is listed in the middleware priority list in bootstrap/app.php, ahead of the
 * authentication middleware, because Laravel would otherwise sort authentication first.
 */
final readonly class ThrottleApiByAddress
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'api-ip|'.$request->ip();
        $max = max(1, config()->integer('budget.api_ip_rate_limit', 120));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return new JsonResponse(['message' => 'Too many requests.'], 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
