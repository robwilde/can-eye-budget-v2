<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\User;
use Laravel\Fortify\Features;
use Tests\Concerns\DisablesRegistration;

uses(DisablesRegistration::class);

$setRegistrationFlag = function (string|false $value): void {
    if ($value === false) {
        putenv('FORTIFY_REGISTRATION_ENABLED');
        unset($_ENV['FORTIFY_REGISTRATION_ENABLED'], $_SERVER['FORTIFY_REGISTRATION_ENABLED']);

        return;
    }

    putenv('FORTIFY_REGISTRATION_ENABLED='.$value);
    $_ENV['FORTIFY_REGISTRATION_ENABLED'] = $value;
    $_SERVER['FORTIFY_REGISTRATION_ENABLED'] = $value;
};

test('the fortify config drops the registration feature when the flag is false', function () use ($setRegistrationFlag) {
    $flagBeforeTest = getenv('FORTIFY_REGISTRATION_ENABLED');

    $setRegistrationFlag('false');

    try {
        $config = require config_path('fortify.php');

        expect($config['features'])->not->toContain(Features::registration());
    } finally {
        $setRegistrationFlag($flagBeforeTest);
    }
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
