<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureMcpEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('budget.mcp') === true && config('budget.api') === true, 404);

        return $next($request);
    }
}
