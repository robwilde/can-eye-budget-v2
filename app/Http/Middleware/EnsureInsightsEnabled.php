<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureInsightsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('budget.insights_enabled') === true, 404);

        return $next($request);
    }
}
