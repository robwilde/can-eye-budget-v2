<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Assistant\AssistantAvailability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureAssistantChatEnabled
{
    public function __construct(private AssistantAvailability $availability) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->availability->chatEnabled(), 404);

        return $next($request);
    }
}
