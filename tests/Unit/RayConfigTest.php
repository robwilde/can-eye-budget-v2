<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;

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

/**
 * Resolve `ray.php` with a controlled `config('app.env')`, isolated from whatever
 * container the surrounding suite happens to have bound.
 *
 * `tests/Unit` is not extended by `Tests\TestCase`, so no application is booted here
 * and `app()->has('config')` would otherwise depend on test ordering. Passing
 * `$configAppEnv === null` reproduces the framework-less `ray()` path, where no
 * `config` repository is bound at all.
 *
 * @param  array<string, string>  $environment
 * @return array<string, mixed>
 */
function rayConfigWithConfiguredAppEnv(array $environment, ?string $configAppEnv): array
{
    $original = Container::getInstance();

    $container = new Container;

    if ($configAppEnv !== null) {
        $container->instance('config', new Repository([
            'app' => ['env' => $configAppEnv],
        ]));
    }

    Container::setInstance($container);

    try {
        return rayConfigForEnvironment($environment);
    } finally {
        Container::setInstance($original);
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

test('a real APP_ENV takes precedence over a differing config app.env', function (string $appEnv, string $configAppEnv, bool $expected) {
    $config = rayConfigWithConfiguredAppEnv(['APP_ENV' => $appEnv], $configAppEnv);

    expect($config['enable'])->toBe($expected);
})->with([
    'production environment beats a stale cache baked at local' => ['production', 'local', false],
    'staging environment beats a stale cache baked at local' => ['staging', 'local', false],
    'local environment beats a cache baked at production' => ['local', 'production', true],
]);

test('config app.env resolves the environment once config:cache has hidden APP_ENV', function (string $configAppEnv, bool $expected) {
    $config = rayConfigWithConfiguredAppEnv([], $configAppEnv);

    expect($config['enable'])->toBe($expected);
})->with([
    'local' => ['local', true],
    'staging' => ['staging', false],
    'production' => ['production', false],
]);

test('ray stays disabled on the framework-less path where no config is bound', function () {
    $config = rayConfigWithConfiguredAppEnv([], null);

    expect($config['enable'])->toBeFalse();
});

test('an explicit RAY_ENABLED still overrides a config-resolved environment', function (string $configAppEnv, string $rayEnabled, bool $expected) {
    $config = rayConfigWithConfiguredAppEnv(['RAY_ENABLED' => $rayEnabled], $configAppEnv);

    expect($config['enable'])->toBe($expected);
})->with([
    'forced on against a production cache' => ['production', 'true', true],
    'forced off against a local cache' => ['local', 'false', false],
]);

test('a present but falsy APP_ENV is not treated as absent and never reaches the cache', function (string $appEnv) {
    $config = rayConfigWithConfiguredAppEnv(['APP_ENV' => $appEnv], 'local');

    expect($config['enable'])->toBeFalse();
})->with([
    'explicitly empty' => [''],
    'the string false' => ['false'],
    'the string empty' => ['empty'],
]);
