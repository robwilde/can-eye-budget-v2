<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BnplProvider;

test('afterpay label is the display name used in plan descriptions', function () {
    expect(BnplProvider::Afterpay->label())->toBe('Afterpay');
});

test('paypal label is cased for display, not for its value', function () {
    expect(BnplProvider::Paypal->value)->toBe('paypal')
        ->and(BnplProvider::Paypal->label())->toBe('PayPal');
});

test('every provider has a label', function () {
    foreach (BnplProvider::cases() as $provider) {
        expect($provider->label())->not->toBe('');
    }
});
