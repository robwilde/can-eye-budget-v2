<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\PreviewNotice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsurePreviewFeatures
{
    /**
     * Applied as `preview` or `preview:budget.some_flag`. A named deployment flag that is off
     * answers 404 for everyone; with it on, only users who opted in to preview features get through
     * and everyone else lands on the dashboard with a notice.
     */
    public function handle(Request $request, Closure $next, ?string $deploymentFlag = null): Response
    {
        abort_if($deploymentFlag !== null && config($deploymentFlag) !== true, 404);

        $user = $request->user();

        if (! $user instanceof User || ! $user->hasPreviewFeatures()) {
            return redirect()->route('dashboard')->with(PreviewNotice::SESSION_KEY, PreviewNotice::message());
        }

        return $next($request);
    }
}
