<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Recurring\RecurringGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureRecurringEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || RecurringGate::enabled($user)) {
            return $next($request);
        }

        return redirect()->route('dashboard')->with('status', __('Recurring bills and income are switched off. Switch them on in Settings → Recurring.'));
    }
}
