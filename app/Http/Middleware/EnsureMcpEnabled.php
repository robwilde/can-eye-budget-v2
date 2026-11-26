<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\InstanceSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureMcpEnabled
{
    public function __construct(private InstanceSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('budget.mcp') === true && config('budget.api') === true && $this->settings->toolsEnabled(), 404);

        return $next($request);
    }
}
