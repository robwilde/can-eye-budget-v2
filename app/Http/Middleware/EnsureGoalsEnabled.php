<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureGoalsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('budget.goals_enabled') === true, 404);

        return $next($request);
    }
}
