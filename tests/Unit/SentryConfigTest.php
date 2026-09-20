<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

/**
 * Resolve `config/sentry.php` against a controlled environment.
 *
 * Every one of Laravel's three env sources is cleared before each key is set, because
 * `Env::getRepository()` consults $_ENV and $_SERVER ahead of putenv — setting only one
 * would let the surrounding suite's APP_ENV=testing win and the assertion would pass
 * for the wrong reason. The snapshot is restored in a finally block so these tests
 * cannot leak an environment into whatever runs next.
 *
 * @param  array<string, string>  $environment
 * @return array<string, mixed>
 */
function sentryConfigForEnvironment(array $environment): array
{
    $keys = ['APP_ENV', 'SENTRY_DSN', 'SENTRY_LARAVEL_DSN'];

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
        return include dirname(__DIR__, 2).'/config/sentry.php';
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

const SENTRY_TEST_DSN = 'https://abc123@o0.ingest.us.sentry.io/999';

test('the local environment gets no dsn even when one is configured', function () {
    $config = sentryConfigForEnvironment([
        'APP_ENV' => 'local',
        'SENTRY_DSN' => SENTRY_TEST_DSN,
    ]);

    expect($config['dsn'])->toBeNull();
});

test('a configured dsn survives in every reporting environment', function (string $appEnv) {
    $config = sentryConfigForEnvironment([
        'APP_ENV' => $appEnv,
        'SENTRY_DSN' => SENTRY_TEST_DSN,
    ]);

    expect($config['dsn'])->toBe(SENTRY_TEST_DSN);
})->with(['staging', 'production']);

test('the local gate also closes the SENTRY_LARAVEL_DSN door', function () {
    $config = sentryConfigForEnvironment([
        'APP_ENV' => 'local',
        'SENTRY_LARAVEL_DSN' => SENTRY_TEST_DSN,
    ]);

    expect($config['dsn'])->toBeNull();
});

test('an unset APP_ENV is not mistaken for local', function () {
    // config:cache can leave APP_ENV absent from the environment. Reporting must fail
    // open here rather than silently going dark on a deployed container.
    $config = sentryConfigForEnvironment(['SENTRY_DSN' => SENTRY_TEST_DSN]);

    expect($config['dsn'])->toBe(SENTRY_TEST_DSN);
});
