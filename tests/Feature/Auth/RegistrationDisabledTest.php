<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\User;

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

$flagBeforeTest = false;
$environmentFileBackup = null;

beforeEach(function () use ($setRegistrationFlag, &$flagBeforeTest, &$environmentFileBackup): void {
    $flagBeforeTest = getenv('FORTIFY_REGISTRATION_ENABLED');

    $testingEnvironmentFile = base_path('.env.testing');
    $environmentFileBackup = file_exists($testingEnvironmentFile)
        ? file_get_contents($testingEnvironmentFile)
        : null;

    $baseEnvironmentFile = file_exists(base_path('.env')) ? base_path('.env') : base_path('.env.example');

    file_put_contents(
        $testingEnvironmentFile,
        file_get_contents($baseEnvironmentFile)."\nFORTIFY_REGISTRATION_ENABLED=false\n",
    );

    $setRegistrationFlag('false');

    $this->refreshApplication();
    $this->restoreInMemoryDatabase();
});

afterEach(function () use ($setRegistrationFlag, &$flagBeforeTest, &$environmentFileBackup): void {
    $testingEnvironmentFile = base_path('.env.testing');

    if ($environmentFileBackup === null) {
        @unlink($testingEnvironmentFile);
    } else {
        file_put_contents($testingEnvironmentFile, $environmentFileBackup);
    }

    $setRegistrationFlag($flagBeforeTest);
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
