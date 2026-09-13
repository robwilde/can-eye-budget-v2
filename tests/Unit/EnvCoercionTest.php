<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

/**
 * Read a value back through the real `env()` coercion, restoring the slot afterwards.
 *
 * The global `env()` helper is used rather than `Illuminate\Support\Env::get()` because
 * it is the surface `docker/entrypoint.sh` is written against, and it is available here:
 * `Illuminate\Support\helpers.php` defines it as a direct `Env::get()` delegation with no
 * container involvement, and Composer's `files` autoload loads it, so it resolves even
 * though `tests/Pest.php` binds `Tests\TestCase` only to Feature and Browser and this
 * file therefore runs as plain PHPUnit with no booted application.
 *
 * The previous value is snapshotted and restored rather than unconditionally unset, so a
 * pre-existing variable of this name in the runner's environment survives and no later
 * test in the same parallel worker can become order-dependent on this one.
 */
function envCoercionOf(string $value): mixed
{
    $previous = getenv('CAN_EYE_ENV_COERCION');

    putenv('CAN_EYE_ENV_COERCION='.$value);

    try {
        return env('CAN_EYE_ENV_COERCION');
    } finally {
        $previous === false
            ? putenv('CAN_EYE_ENV_COERCION')
            : putenv('CAN_EYE_ENV_COERCION='.$previous);
    }
}

/**
 * Pin the `env()` sentinel decoding that `docker/entrypoint.sh:65-67` mirrors.
 *
 * That shell block unsets `APP_ENV` for exactly the literals the framework decodes as
 * PHP null, case-insensitively and without trimming, so a `null` reaching the container
 * means the same thing to the shell and to `config('app.env')`. The mirror is hand-written
 * and unenforced: if a `composer update` adds a `trim()`, drops a case, makes the match
 * case-sensitive, or introduces a new sentinel, the block silently stops agreeing with
 * `env()` and the `APP_ENV=null` hole PR #427 closed reopens with no diff to any file in
 * this repository. The comment above the block cites vendor line numbers, which a bump
 * can move without failing anything -- these tests fail instead.
 *
 * Asserted on the returned value, never on `Env.php` source text or line numbers.
 */
test('the entrypoint sentinel block mirrors this: env() decodes null and (null) as php null', function (string $exported) {
    expect(envCoercionOf($exported))->toBeNull();
})->with([
    'null, the literal the shell block unsets' => ['null'],
    '(null), the parenthesised form the shell block also unsets' => ['(null)'],
    'NULL, because the framework lowercases before matching' => ['NULL'],
    'nUlL, so the shell character classes must stay case-insensitive' => ['nUlL'],
    '(NULL), the parenthesised form in upper case' => ['(NULL)'],
]);

test('the entrypoint sentinel block mirrors this: env() does not trim, so near-misses stay strings', function (string $exported) {
    expect(envCoercionOf($exported))->toBe($exported);
})->with([
    'surrounding whitespace is not a sentinel, which is why the block leaves it alone' => [' null '],
    'a value merely starting with null is untouched' => ['nullx'],
    'a value merely ending with null is untouched' => ['xnull'],
]);

test('the entrypoint sentinel block mirrors this: the neighbouring sentinels decode as values, not absence', function (string $exported, string|bool $expected) {
    expect(envCoercionOf($exported))->toBe($expected);
})->with([
    'false is a value, so the block deliberately keeps it' => ['false', false],
    '(false) is a value' => ['(false)', false],
    'true is a value' => ['true', true],
    '(true) is a value' => ['(true)', true],
    'empty decodes to the empty string, not absence' => ['empty', ''],
    '(empty) decodes to the empty string' => ['(empty)', ''],
]);
