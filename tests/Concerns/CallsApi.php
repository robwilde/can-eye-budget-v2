<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\ApiKeyScope;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Helpers for API tests: issue a key for a user and call /api/v1 with it, resetting the cached
 * guard between calls so each request authenticates from its own token.
 */
trait CallsApi
{
    protected function apiTokenFor(User $user, ApiKeyScope $scope = ApiKeyScope::ReadWrite, string $name = 'test'): string
    {
        config(['budget.api' => true]);

        return ApiKey::issue($user, $name, $scope, null)['token'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function apiCall(string $method, string $uri, string $token, array $data = []): TestResponse
    {
        app('auth')->forgetGuards();

        return $this->withToken($token)->json($method, '/api/v1/'.mb_ltrim($uri, '/'), $data);
    }
}
