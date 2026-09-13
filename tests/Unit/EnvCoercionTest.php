<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use Illuminate\Support\Env;

/**
 * Read a value back through the real `Env::get()` coercion, then restore the slot.
 *
 * `Env::get()` is called directly rather than through the `env()` helper because
 * `tests/Pest.php` binds `Tests\TestCase` only to Feature and Browser, so this file
 * runs as plain PHPUnit with no booted application and no container to resolve the
 * helper against. `Env::get()` is the same framework code path the helper delegates
 * to, so the contract under test is the framework's, not a reimplementation of it.
 */
function envCoercionOf(string $value): mixed
{
    putenv('CAN_EYE_ENV_COERCION='.$value);

    try {
        return Env::get('CAN_EYE_ENV_COERCION');
    } finally {
        putenv('CAN_EYE_ENV_COERCION');
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
