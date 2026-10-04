<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Support\CleanDescriptionDeriver;

test('a narration becomes a readable name without codes or masked account numbers', function (string $narration, string $expected) {
    expect(CleanDescriptionDeriver::fromDescription($narration))->toBe($expected);
})->with([
    'store and suburb' => ['WOOLWORTHS 1234 BONDI', 'Woolworths Bondi'],
    'direct debit with masked account' => ['Direct Debit NIB - xxxx9390', 'Direct Debit Nib'],
    'savings transfer with masked account' => ['Osko Payment To Savings Account xxxx1111 YOU - Savings Ref#xxxxx2222', 'Osko Payment To Savings Account You Savings'],
    'atm code joined to the payee' => ['ATM#009198-BOUNDARY ST - WEST END   BRISBANE     AU 2892', 'Atm Boundary St West End Brisbane Au'],
    'leading card network' => ['VISA WOOLWORTHS 1234 SYDNEY', 'Woolworths Sydney'],
    'dangling preposition after a mask' => ['Round Up transfer to xxxx4599', 'Round Up Transfer'],
    'a trailing preposition kept when the mask is elsewhere' => ['Pay xxxx1234 Back To', 'Pay Back To'],
]);

test('a narration with nothing meaningful left gets no name', function (?string $narration) {
    expect(CleanDescriptionDeriver::fromDescription($narration))->toBeNull();
})->with([
    'null' => [null],
    'blank' => ['   '],
    'reference only' => ['Ref#884905699 2422732337'],
    'masked account only' => ['xxxx9390'],
    'card network and mask' => ['VISA xxxx1234'],
]);

test('a derived name never exceeds the column length', function () {
    $name = CleanDescriptionDeriver::fromDescription(str_repeat('ALPHA BRAVO ', 40));

    expect(mb_strlen((string) $name))->toBe(255);
});

test('tidy collapses whitespace, caps the length and turns blank into null', function () {
    expect(CleanDescriptionDeriver::tidy("  Acme \t hosting  "))->toBe('Acme hosting')
        ->and(CleanDescriptionDeriver::tidy(" \t "))->toBeNull()
        ->and(mb_strlen((string) CleanDescriptionDeriver::tidy(str_repeat('a', 300))))->toBe(255);
});
