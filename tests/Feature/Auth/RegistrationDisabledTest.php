<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\User;

beforeEach(function (): void {
    putenv('FORTIFY_REGISTRATION_ENABLED=false');
    $_ENV['FORTIFY_REGISTRATION_ENABLED'] = 'false';
    $_SERVER['FORTIFY_REGISTRATION_ENABLED'] = 'false';

    $this->refreshApplication();
    $this->restoreInMemoryDatabase();
});

afterEach(function (): void {
    putenv('FORTIFY_REGISTRATION_ENABLED');
    unset($_ENV['FORTIFY_REGISTRATION_ENABLED'], $_SERVER['FORTIFY_REGISTRATION_ENABLED']);
});

test('the registration screen is gone when registration is disabled', function () {
    $this->get('/register')->assertNotFound();
});

test('an account cannot be created when registration is disabled', function () {
    $this->post('/register', [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    expect(User::query()->where('email', 'test@example.com')->exists())->toBeFalse();
});

test('the landing page renders without a sign-up link when registration is disabled', function () {
    $response = $this->get('/');

    $response->assertOk();

    expect($response->getContent())->not->toContain('/register');
});

test('an existing user can still log in when registration is disabled', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});
