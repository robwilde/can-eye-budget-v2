<?php

declare(strict_types=1);

use App\Http\Middleware\AddMcpAuthenticateHeader;
use App\Http\Middleware\AttributeRequestToUser;
use App\Http\Middleware\EnsureMcpEnabled;
use App\Http\Middleware\ThrottleApiByAddress;
use App\Mcp\Servers\BudgetServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;

/*
|--------------------------------------------------------------------------
| MCP server
|--------------------------------------------------------------------------
|
| The assistant's tools for external clients. The flags and the per-address limiter run first, then the same token
| guard, read ability and per-key limiter as the REST API; a key that cannot write never sees a tool that changes data.
| The metadata route is what a 401 points clients to: it says the server takes a personal API key as a bearer token.
|
*/
Route::middleware(EnsureMcpEnabled::class)->group(static function (): void {
    Mcp::web('/mcp', BudgetServer::class)
        ->name('mcp.server')
        ->withoutMiddleware(AddWwwAuthenticateHeader::class)
        ->middleware([AddMcpAuthenticateHeader::class, ThrottleApiByAddress::class, 'auth:sanctum', 'abilities:read', 'throttle:api-key', AttributeRequestToUser::class]);
});

Route::get('/.well-known/oauth-protected-resource/{path?}', static fn (?string $path = '') => response()->json([
    'resource' => url('/mcp'),
    'bearer_methods_supported' => ['header'],
    'resource_name' => 'Can I Budget',
    'resource_documentation' => url('/settings/api-keys'),
]))->where('path', '.*')->middleware(EnsureMcpEnabled::class)->name('mcp.oauth.protected-resource');
