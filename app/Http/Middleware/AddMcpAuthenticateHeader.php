<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A 401 from the MCP endpoint points the client at the protected-resource metadata, which says the server takes a
 * personal API key as a bearer token.
 */
final readonly class AddMcpAuthenticateHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() === 401) {
            $response->headers->set('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.route('mcp.oauth.protected-resource', ['path' => $request->path()]).'"');
        }

        return $response;
    }
}
