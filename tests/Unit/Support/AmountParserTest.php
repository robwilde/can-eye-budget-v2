<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Support\AmountParser;
use App\Support\AmountParseResult;

test('parse returns AmountParseResult instance', function () {
    expect(AmountParser::parse('42'))->toBeInstanceOf(AmountParseResult::class);
});

test('AmountParseResult has typed properties', function () {
    $result = new AmountParseResult(4200, 'groceries');

    expect($result->amount)->toBe(4200)
        ->and($result->description)->toBe('groceries');
});

test('parse extracts amount and description', function (string $input, int $expectedCents, string $expectedDescription) {
    $result = AmountParser::parse($input);

    expect($result->amount)->toBe($expectedCents)
        ->and($result->description)->toBe($expectedDescription);
})->with([
    'math with description' => ['4*15 zoo tickets (100 in parentheses is ignored)', 6000, 'zoo tickets'],
    'simple decimal' => ['25.50', 2550, ''],
    'addition with description' => ['100+50 groceries', 15000, 'groceries'],
    'plain integer' => ['42', 4200, ''],
    'description only' => ['rent', 0, 'rent'],
    'empty string' => ['', 0, ''],
    'only parenthetical text' => ['(ignore this)', 0, ''],
    'decimal multiplication' => ['3.50*2 coffees', 700, 'coffees'],
    'subtraction' => ['100-25 refund', 7500, 'refund'],
    'division' => ['100/4 split bill', 2500, 'split bill'],
    'mixed operators with precedence' => ['10+5*2 snacks', 2000, 'snacks'],
    'negative result' => ['10-50 overdraft', -4000, 'overdraft'],
    'large number' => ['99999.99', 9999999, ''],
    'whitespace in expression' => ['4 * 15 zoo', 6000, 'zoo'],
    'division by zero' => ['10/0 oops', 0, 'oops'],
    'leading-dot decimal' => ['.50 tip', 50, 'tip'],
    'unary negative' => ['-10 refund', -1000, 'refund'],
    'unary positive' => ['+25 bonus', 2500, 'bonus'],
    'nested parentheses' => ['50 rent (April (late))', 5000, 'rent'],
]);
