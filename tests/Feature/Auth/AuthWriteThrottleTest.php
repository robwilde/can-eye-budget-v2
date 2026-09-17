<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * @return list<string>
 */
function throttleMiddleware(string $routeName): array
{
    $route = Route::getRoutes()->getByName($routeName);

    return array_values(array_filter(
        $route->gatherMiddleware(),
        fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'throttle:'),
    ));
}

test('the registration and password reset endpoints resolve to throttled routes', function () {
    expect(throttleMiddleware('register.store'))->toBe(['throttle:register'])
        ->and(throttleMiddleware('password.email'))->toBe(['throttle:password-reset'])
        ->and(Route::getRoutes()->getByName('register.store')->gatherMiddleware())->toContain('guest:web')
        ->and(Route::getRoutes()->getByName('password.email')->gatherMiddleware())->toContain('guest:web');
});

test('the login limiter is left alone and not double applied', function () {
    expect(throttleMiddleware('login.store'))->toBe(['throttle:login']);
});

test('registration requests under the limit are not throttled', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post('/register', [
            'name' => 'John Doe',
            'email' => "under-{$attempt}@example.com",
            'password' => 'password',
            'password_confirmation' => 'password',
        ], ['X-Forwarded-For' => '203.0.113.10'])->assertRedirect();
    }
});

test('registration returns 429 once one client exceeds the limit', function () {
    foreach (range(1, 5) as $ignored) {
        $this->post('/register', [], ['X-Forwarded-For' => '203.0.113.10']);
    }

    $this->post('/register', [], ['X-Forwarded-For' => '203.0.113.10'])->assertStatus(429);
});

test('a registration burst from one client leaves other clients unthrottled', function () {
    foreach (range(1, 6) as $ignored) {
        $this->post('/register', [], ['X-Forwarded-For' => '203.0.113.10']);
    }

    $this->post('/register', [], ['X-Forwarded-For' => '203.0.113.10'])->assertStatus(429);
    $this->post('/register', [], ['X-Forwarded-For' => '198.51.100.7'])->assertStatus(302);
});

test('password reset returns 429 once one client exceeds the request limit', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post('/forgot-password', ['email' => "nobody-{$attempt}@example.com"], ['X-Forwarded-For' => '203.0.113.20'])
            ->assertStatus(302);
    }

    $this->post('/forgot-password', ['email' => 'nobody-6@example.com'], ['X-Forwarded-For' => '203.0.113.20'])
        ->assertStatus(429);
});

test('password reset mail for one address is capped across clients', function () {
    foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3'] as $client) {
        $this->post('/forgot-password', ['email' => 'victim@example.com'], ['X-Forwarded-For' => $client])
            ->assertStatus(302);
    }

    $this->post('/forgot-password', ['email' => 'victim@example.com'], ['X-Forwarded-For' => '198.51.100.4'])
        ->assertStatus(429);

    $this->post('/forgot-password', ['email' => 'bystander@example.com'], ['X-Forwarded-For' => '198.51.100.4'])
        ->assertStatus(302);
});
