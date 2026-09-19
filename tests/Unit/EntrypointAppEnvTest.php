<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

/**
 * Resolve `APP_ENV` the way a container actually does: by running the real
 * `docker/entrypoint.sh` and reading the variable it exported.
 *
 * The script is executed rather than reproduced, so this cannot drift away from
 * the file it pins -- a copied or line-sliced excerpt would keep passing after
 * the block moved. `php` and `chown` are stubbed onto `PATH` because the artisan
 * calls need a database and the `chown` needs root; neither is part of the
 * contract under test, and stubbing them keeps this hermetic and Docker-free.
 * `exec "$@"` at the end of the script runs the probe, which reports the value
 * as the first process to inherit it sees it.
 *
 * @param  string|null  $exported  `APP_ENV` in the container environment, or null to leave it unset
 * @param  string|null  $dotEnv  value for an `APP_ENV=` line in `.env`, or null to write no `.env`
 */
function entrypointAppEnv(?string $exported, ?string $dotEnv): string
{
    // Delimited because exec() strips trailing whitespace from captured lines,
    // which would quietly turn a ' null ' that the script correctly preserved
    // into a passing ' null' and hide the untrimmed-match contract.
    $probe = 'printf "[[%s]]" "${APP_ENV-<unset>}"';

    $run = runEntrypoint(
        stubs: ['php' => 'exit 0', 'chown' => 'exit 0'],
        env: $exported === null ? [] : ['APP_ENV' => $exported],
        arguments: ['sh', '-c', $probe],
        files: $dotEnv === null ? [] : ['.env' => 'APP_ENV='.$dotEnv."\n"],
    );

    expect($run['status'])->toBe(0);

    $captured = implode("\n", $run['stdout']);

    expect($captured)->toMatch('/^\[\[.*]]$/s');

    return mb_substr($captured, 2, -2);
}

test('the entrypoint exports an APP_ENV for every container it boots', function (?string $exported, ?string $dotEnv, string $expected) {
    expect(entrypointAppEnv($exported, $dotEnv))->toBe($expected);
})->with([
    'no source at all falls back to production' => [null, null, 'production'],
    '.env supplies the value when nothing is exported' => [null, 'local', 'local'],
    'an exported value beats .env' => ['staging', 'local', 'staging'],
    'an exported production survives a .env saying local' => ['production', 'local', 'production'],
]);

test('a present but non-local APP_ENV is never rewritten to production', function (string $exported, string $expected) {
    expect(entrypointAppEnv($exported, null))->toBe($expected);
})->with([
    'an explicitly empty value is a deliberate non-local signal' => ['', ''],
    'the string false is a value env() reports' => ['false', 'false'],
    'the string empty is a value env() reports' => ['empty', 'empty'],
    'a real environment name is untouched' => ['staging', 'staging'],
    'local is untouched, so a developer machine still enables Ray' => ['local', 'local'],
]);

test('the literals Laravel decodes as null are treated as absent', function (?string $exported, ?string $dotEnv) {
    expect(entrypointAppEnv($exported, $dotEnv))->toBe('production');
})->with([
    'exported null' => ['null', null],
    'exported (null)' => ['(null)', null],
    'exported NULL, because Env.php lowercases before matching' => ['NULL', null],
    'exported mixed case nUlL' => ['nUlL', null],
    'exported (NULL)' => ['(NULL)', null],
    'null from .env' => [null, 'null'],
    '(null) from .env' => [null, '(null)'],
]);

test('only the exact sentinels are absent, matching Env.php without trimming', function (string $exported, string $expected) {
    expect(entrypointAppEnv($exported, null))->toBe($expected);
})->with([
    'surrounding whitespace is not a sentinel to env() either' => [' null ', ' null '],
    'a value merely starting with null is untouched' => ['nullx', 'nullx'],
    'a value merely ending with null is untouched' => ['xnull', 'xnull'],
]);
