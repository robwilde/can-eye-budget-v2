<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ApiKeyScope;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CallsApi;

uses(CallsApi::class);

/**
 * @return list<array{0: string, 1: string}>
 */
function apiWriteRoutesForScopeTest(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (IlluminateRoute $route): bool => str_starts_with($route->uri(), 'api/v1/') && array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()) !== [])
        ->map(fn (IlluminateRoute $route): array => [array_values(array_diff($route->methods(), ['HEAD']))[0], str_replace(['api/v1/', '{id}'], ['', '1'], $route->uri())])
        ->values()
        ->all();
}

it('refuses every API write route for a read-only key before anything runs', function () {
    $user = User::factory()->create();
    $token = $this->apiTokenFor($user, ApiKeyScope::Read);

    foreach (apiWriteRoutesForScopeTest() as [$method, $uri]) {
        $this->apiCall($method, $uri, $token, ['name' => 'x'])->assertForbidden()->assertJsonPath('message', 'insufficient scope');
    }

    expect(AuditEvent::query()->where('user_id', $user->id)->where('outcome', 'failure')->count())->toBe(count(apiWriteRoutesForScopeTest()));
});

it('refuses every API write route without a token', function () {
    config(['budget.api' => true]);

    foreach (apiWriteRoutesForScopeTest() as [$method, $uri]) {
        $this->json($method, '/api/v1/'.$uri, [])->assertUnauthorized();
    }
});

it('hides every API write route while the api flag is off', function () {
    $user = User::factory()->create();
    $token = $this->apiTokenFor($user);
    config(['budget.api' => false]);

    foreach (apiWriteRoutesForScopeTest() as [$method, $uri]) {
        $this->apiCall($method, $uri, $token)->assertNotFound();
    }

    expect(AuditEvent::query()->count())->toBe(0);
});

it('refuses every API write route for a revoked or expired key', function () {
    $user = User::factory()->create();
    $revoked = $this->apiTokenFor($user, name: 'revoked');
    App\Models\ApiKey::query()->where('name', 'revoked')->update(['revoked_at' => now()]);
    $expired = $this->apiTokenFor($user, name: 'expired');
    App\Models\ApiKey::query()->where('name', 'expired')->update(['expires_at' => now()->subSecond()]);

    foreach (apiWriteRoutesForScopeTest() as [$method, $uri]) {
        $this->apiCall($method, $uri, $revoked)->assertUnauthorized();
        $this->apiCall($method, $uri, $expired)->assertUnauthorized();
    }
});

it('counts write requests toward the per-key rate limit', function () {
    $user = User::factory()->create();
    $token = $this->apiTokenFor($user);
    config(['budget.api_key_rate_limit' => 2, 'budget.api_ip_rate_limit' => 1000]);

    $this->apiCall('POST', 'transactions', $token, [])->assertUnprocessable();
    $this->apiCall('POST', 'transactions', $token, [])->assertUnprocessable();
    $this->apiCall('POST', 'transactions', $token, [])->assertStatus(429);
});

it('does not report a rule refusal as an error', function () {
    $user = User::factory()->create();
    $row = App\Models\Transaction::factory()->for($user)->debit()->create(['amount' => 900, 'source' => App\Enums\TransactionSource::Redbark]);

    expect(app(Illuminate\Contracts\Debug\ExceptionHandler::class)->shouldReport(new App\Exceptions\TransactionWriteRefusedException('refused')))->toBeFalse();

    $this->apiCall('PATCH', 'transactions/'.$row->id, $this->apiTokenFor($user), ['amount' => 1])->assertUnprocessable();
});

it('binds the Sentry actor to the token owner when a key authenticates', function () {
    $user = User::factory()->create();
    $token = $this->apiTokenFor($user);
    Sentry\configureScope(fn (Sentry\State\Scope $scope) => $scope->removeUser());

    $this->apiCall('GET', 'accounts', $token)->assertOk();

    $bound = null;
    Sentry\configureScope(function (Sentry\State\Scope $scope) use (&$bound): void {
        $bound = $scope->getUser()?->getId();
    });

    expect($bound)->toBe((string) $user->id);
});
