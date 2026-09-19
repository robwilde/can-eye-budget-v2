<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

/**
 * Pin the process each role boots, by running the real `docker/entrypoint.sh`
 * and reporting the command it finally `exec`s.
 *
 * The script is executed rather than reproduced, so this cannot drift away from
 * the file it pins. What it cannot observe is the premise that makes the
 * no-argument path reachable at all: the image declares no `CMD`. That is
 * enforced by the `Dockerfile` alone and is deliberately not covered here --
 * with a `CMD` restored, every row below would stay green while a `horizon`
 * container silently booted the web stack. A Dokploy Application that sets its
 * command field is the same failure by another route: it maps to Swarm
 * `ContainerSpec.Command` and replaces the ENTRYPOINT, skipping role
 * validation, the caches, the chown and the privilege drop.
 *
 * `php`, `chown`, `supervisord` and `su-exec` are stubbed onto `PATH`: the stubs
 * echo their own invocation, so the last line of output is whatever the script
 * handed to `exec`. The earlier `php artisan` setup calls appear on earlier
 * lines and are not part of this contract.
 */

/**
 * @param  string|null  $role  `CONTAINER_ROLE` in the container environment, or null to leave it unset
 * @param  list<string>  $arguments  an explicit argument list, as a Dokploy `args` override would deliver
 * @return array{status: int, stdout: list<string>, stderr: string}
 */
function entrypointRun(?string $role, array $arguments = []): array
{
    $echo = static fn (string $name): string => "printf '%s %s\\n' ".escapeshellarg($name).' "$*"';

    return runEntrypoint(
        stubs: ['php' => $echo('php'), 'chown' => $echo('chown'), 'supervisord' => $echo('supervisord'), 'su-exec' => $echo('su-exec')],
        env: ['APP_ENV' => 'staging'] + ($role === null ? [] : ['CONTAINER_ROLE' => $role]),
        arguments: $arguments,
    );
}

/**
 * @param  list<string>  $arguments
 */
function entrypointDispatch(?string $role, array $arguments = []): string
{
    $run = entrypointRun($role, $arguments);

    expect($run['status'])->toBe(0);
    expect($run['stdout'])->not->toBeEmpty();

    return mb_trim((string) array_pop($run['stdout']));
}

test('each role boots its own process with no command override', function (?string $role, string $expected) {
    expect(entrypointDispatch($role))->toBe($expected);
})->with([
    'web' => ['web', 'supervisord -c /etc/supervisord.conf'],
    'horizon' => ['horizon', 'su-exec www-data php artisan horizon'],
    'scheduler' => ['scheduler', 'su-exec www-data php artisan schedule:work'],
    'unset defaults to web' => [null, 'supervisord -c /etc/supervisord.conf'],
]);

test('an explicit argument list overrides the role default', function (?string $role, string $expected) {
    expect(entrypointDispatch($role, ['php', 'artisan', 'tinker']))->toBe($expected);
})->with([
    'web keeps root' => ['web', 'php artisan tinker'],
    'horizon still drops privileges' => ['horizon', 'su-exec www-data php artisan tinker'],
]);

test('a blank or unknown CONTAINER_ROLE aborts before any process is resolved', function (string $role, array $arguments, string $diagnostic) {
    $run = entrypointRun($role, $arguments);

    expect($run['status'])->toBe(1)
        ->and($run['stderr'])->toContain($diagnostic)
        ->and($run['stdout'])->toBe([]);
})->with([
    'blank, no arguments' => ['', [], 'entrypoint: CONTAINER_ROLE is set but empty; expected web, horizon, or scheduler'],
    'unknown, no arguments' => ['worker', [], "entrypoint: unknown CONTAINER_ROLE 'worker'; expected web, horizon, or scheduler"],
    'blank beats an explicit argument list' => ['', ['php', 'artisan', 'tinker'], 'entrypoint: CONTAINER_ROLE is set but empty; expected web, horizon, or scheduler'],
    'unknown beats an explicit argument list' => ['worker', ['php', 'artisan', 'tinker'], "entrypoint: unknown CONTAINER_ROLE 'worker'; expected web, horizon, or scheduler"],
]);
