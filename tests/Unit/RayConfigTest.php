<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

/**
 * @param  array<string, string>  $environment
 * @return array<string, mixed>
 */
function rayConfigForEnvironment(array $environment): array
{
    $keys = ['APP_ENV', 'RAY_ENABLED', 'RAY_LOCAL_PATH'];

    $set = function (string $key, ?string $value): void {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);

        if ($value !== null) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    };

    $restore = [];

    foreach ($keys as $key) {
        $restore[$key] = isset($_SERVER[$key]) ? (string) $_SERVER[$key] : null;
        $set($key, $environment[$key] ?? null);
    }

    try {
        return include dirname(__DIR__, 2).'/ray.php';
    } finally {
        foreach ($restore as $key => $value) {
            $set($key, $value);
        }
    }
}

test('ray is enabled by default only in the local environment', function (?string $appEnv, bool $expected) {
    $environment = $appEnv === null ? [] : ['APP_ENV' => $appEnv];

    expect(rayConfigForEnvironment($environment)['enable'])->toBe($expected);
})->with([
    'local' => ['local', true],
    'testing' => ['testing', false],
    'staging' => ['staging', false],
    'production' => ['production', false],
    'no APP_ENV at all' => [null, false],
]);

test('an explicit RAY_ENABLED overrides the environment default', function (string $appEnv, string $rayEnabled, bool $expected) {
    $config = rayConfigForEnvironment(['APP_ENV' => $appEnv, 'RAY_ENABLED' => $rayEnabled]);

    expect($config['enable'])->toBe($expected);
})->with([
    'forced on in production' => ['production', 'true', true],
    'forced on in staging' => ['staging', 'true', true],
    'forced off in local' => ['local', 'false', false],
]);

test('local_path is unset so origin paths are never rewritten', function () {
    expect(rayConfigForEnvironment(['APP_ENV' => 'local'])['local_path'])->toBeNull();
});
