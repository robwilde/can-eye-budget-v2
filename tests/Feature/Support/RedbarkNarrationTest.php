<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Support\RedbarkNarration;

test('the authorisation placeholder is recognised whatever its casing', function (string $description) {
    expect(RedbarkNarration::isPlaceholder($description))->toBeTrue();
})->with([
    'AUTHORISATION',
    'authorisation',
    '  Authorisation  ',
    'AUTHORIZATION',
]);

test('a real narration is not a placeholder', function (string $description) {
    expect(RedbarkNarration::isPlaceholder($description))->toBeFalse();
})->with([
    'VISA -HARRIS FARM MARKETS    WEST END     AU',
    'Direct Debit NIB - 64699390',
    'AUTHORISATION FEE',
    '',
]);

test('a placeholder narration marks the row as an uncleared hold', function () {
    // Redbark labels holds "posted", so the description is the only signal.
    expect(RedbarkNarration::isHold('AUTHORISATION', 'posted'))->toBeTrue()
        ->and(RedbarkNarration::isHold('VISA -JETBRAINS', 'posted'))->toBeFalse();
});

test('an explicit pending status is still honoured', function () {
    expect(RedbarkNarration::isHold('VISA -JETBRAINS', 'pending'))->toBeTrue();
});

test('a placeholder narration falls back to the merchant', function () {
    expect(RedbarkNarration::describe('AUTHORISATION', 'HARRIS FARM MARKETS PTY LWEST END     AU'))
        ->toBe('HARRIS FARM MARKETS PTY LWEST END     AU');
});

test('the bank narration wins over the merchant when it says something', function () {
    // The CSV statement carries this fuller form, and adoption matching depends on it.
    expect(RedbarkNarration::describe('VISA -Afterpay    afterpay.com AU  145377', 'Afterpay'))
        ->toBe('VISA -Afterpay    afterpay.com AU  145377');
});

test('a row with neither is never left blank', function () {
    expect(RedbarkNarration::describe(null, null))->toBe('Transaction')
        ->and(RedbarkNarration::describe('AUTHORISATION', null))->toBe('AUTHORISATION');
});
