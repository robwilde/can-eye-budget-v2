<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

/**
 * Pin the process each role boots, by running the real `docker/entrypoint.sh`
 * and reporting the command it finally `exec`s.
 *
 * The image declares no `CMD`, so a container started with no override reaches
 * the script with no arguments and the script itself resolves the process from
 * `CONTAINER_ROLE`. That resolution is the contract: a Dokploy Application that
 * sets its command field instead would map to Swarm `ContainerSpec.Command` and
 * replace this ENTRYPOINT, skipping role validation, the caches, the chown and
 * the privilege drop.
 *
 * The script is executed rather than reproduced, so this cannot drift away from
 * the file it pins. `php`, `chown`, `supervisord` and `su-exec` are stubbed onto
 * `PATH`: the stubs echo their own invocation, so the last line of output is
 * whatever the script handed to `exec`. The earlier `php artisan` setup calls
 * appear on earlier lines and are not part of this contract.
 *
 * @param  string|null  $role  `CONTAINER_ROLE` in the container environment, or null to leave it unset
 * @param  list<string>  $arguments  an explicit argument list, as a Dokploy `args` override would deliver
 */
function entrypointDispatch(?string $role, array $arguments = []): string
{
    $root = dirname(__DIR__, 2);
    $script = $root.'/docker/entrypoint.sh';

    $base = sys_get_temp_dir().'/entrypoint-dispatch-'.getmypid().'-'.bin2hex(random_bytes(8));
    $work = $base.'/work';

    mkdir($base.'/bin', 0o755, true);
    mkdir($work, 0o755, true);

    foreach (['php', 'chown', 'supervisord', 'su-exec'] as $stub) {
        file_put_contents($base.'/bin/'.$stub, "#!/bin/sh\nprintf '%s %s\\n' ".escapeshellarg($stub).' "$*"'."\n");
        chmod($base.'/bin/'.$stub, 0o755);
    }

    $assignments = 'PATH='.escapeshellarg($base.'/bin:/usr/bin:/bin')
        .' APP_ENV=staging';

    if ($role !== null) {
        $assignments .= ' CONTAINER_ROLE='.escapeshellarg($role);
    }

    $command = 'cd '.escapeshellarg($work)
        .' && env -i '.$assignments
        .' sh '.escapeshellarg($script);

    foreach ($arguments as $argument) {
        $command .= ' '.escapeshellarg($argument);
    }

    $command .= ' 2>/dev/null';

    $output = [];
    $status = 0;
    exec($command, $output, $status);

    deleteDispatchFixture($base);

    expect($status)->toBe(0);
    expect($output)->not->toBeEmpty();

    return mb_trim((string) array_pop($output));
}

function deleteDispatchFixture(string $path): void
{
    if (! is_dir($path)) {
        @unlink($path);

        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            deleteDispatchFixture($path.'/'.$entry);
        }
    }

    @rmdir($path);
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
