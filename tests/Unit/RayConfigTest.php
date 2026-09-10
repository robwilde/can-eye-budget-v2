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

    $clear = function (string $key): void {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    };

    $snapshot = [];

    foreach ($keys as $key) {
        $snapshot[$key] = [
            'env' => $_ENV[$key] ?? null,
            'server' => $_SERVER[$key] ?? null,
            'putenv' => getenv($key),
        ];

        $clear($key);

        if (isset($environment[$key])) {
            $_ENV[$key] = $environment[$key];
            $_SERVER[$key] = $environment[$key];
            putenv("{$key}={$environment[$key]}");
        }
    }

    try {
        return include dirname(__DIR__, 2).'/ray.php';
    } finally {
        foreach ($snapshot as $key => $sources) {
            $clear($key);

            if ($sources['env'] !== null) {
                $_ENV[$key] = $sources['env'];
            }

            if ($sources['server'] !== null) {
                $_SERVER[$key] = $sources['server'];
            }

            if ($sources['putenv'] !== false) {
                putenv("{$key}={$sources['putenv']}");
            }
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

test('local_path stays null so origin paths are never rewritten', function (array $environment) {
    expect(rayConfigForEnvironment($environment)['local_path'])->toBeNull();
})->with([
    'no RAY_LOCAL_PATH' => [['APP_ENV' => 'local']],
    'blank RAY_LOCAL_PATH' => [['APP_ENV' => 'local', 'RAY_LOCAL_PATH' => '']],
]);

test('an explicit RAY_LOCAL_PATH is passed through', function () {
    $config = rayConfigForEnvironment(['APP_ENV' => 'local', 'RAY_LOCAL_PATH' => '/home/dev/project']);

    expect($config['local_path'])->toBe('/home/dev/project');
});
