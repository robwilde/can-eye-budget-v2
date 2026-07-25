<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BnplProvider;

test('all bnpl provider cases exist', function () {
    expect(BnplProvider::cases())->toHaveCount(5);
});

test('bnpl provider has correct backing values', function () {
    expect(BnplProvider::Afterpay->value)->toBe('afterpay')
        ->and(BnplProvider::Zip->value)->toBe('zip')
        ->and(BnplProvider::Klarna->value)->toBe('klarna')
        ->and(BnplProvider::Paypal->value)->toBe('paypal')
        ->and(BnplProvider::Humm->value)->toBe('humm');
});

test('bnpl provider resolves from backing value', function () {
    expect(BnplProvider::from('afterpay'))->toBe(BnplProvider::Afterpay)
        ->and(BnplProvider::from('zip'))->toBe(BnplProvider::Zip)
        ->and(BnplProvider::from('klarna'))->toBe(BnplProvider::Klarna)
        ->and(BnplProvider::from('paypal'))->toBe(BnplProvider::Paypal)
        ->and(BnplProvider::from('humm'))->toBe(BnplProvider::Humm);
});

test('bnpl provider has labels', function () {
    expect(BnplProvider::Afterpay->label())->toBe('Afterpay')
        ->and(BnplProvider::Zip->label())->toBe('Zip')
        ->and(BnplProvider::Klarna->label())->toBe('Klarna')
        ->and(BnplProvider::Paypal->label())->toBe('PayPal')
        ->and(BnplProvider::Humm->label())->toBe('Humm');
});

test('paypal label is cased for display, not for its value', function () {
    expect(BnplProvider::Paypal->value)->toBe('paypal')
        ->and(BnplProvider::Paypal->label())->toBe('PayPal');
});
