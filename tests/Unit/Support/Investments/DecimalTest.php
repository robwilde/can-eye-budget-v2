<?php

declare(strict_types=1);

use App\Support\Investments\Decimal;

it('rounds money half away from zero', function () {
    expect(Decimal::toCents('2.5'))->toBe(3)
        ->and(Decimal::toCents('-2.5'))->toBe(-3)
        ->and(Decimal::toCents('2.4999'))->toBe(2)
        ->and(Decimal::toCents('-2.4999'))->toBe(-2)
        ->and(Decimal::toCents('0'))->toBe(0);
});

it('multiplies a fractional quantity by cents without floats', function () {
    expect(Decimal::mulCents('0.12345678', 10000))->toBe(1235)
        ->and(Decimal::mulCents('-0.12345678', 10000))->toBe(-1235)
        ->and(Decimal::mulCents('1000000.5', 3))->toBe(3000002);
});

it('spreads cents over a quantity and refuses zero shares', function () {
    expect(Decimal::perShare(10000, '10'))->toBe(1000)
        ->and(Decimal::perShare(10000, '3'))->toBe(3333)
        ->and(fn () => Decimal::perShare(10000, '0'))->toThrow(InvalidArgumentException::class);
});

it('keeps eight places and trims them for display', function () {
    expect(Decimal::of('1.5'))->toBe('1.50000000')
        ->and(Decimal::of(3))->toBe('3.00000000')
        ->and(Decimal::trim('1.50000000'))->toBe('1.5')
        ->and(Decimal::trim('10.00000000'))->toBe('10')
        ->and(Decimal::abs('-2.25'))->toBe('2.25000000')
        ->and(Decimal::negate('2'))->toBe('-2.00000000')
        ->and(Decimal::compare('0.1', '0.10000000'))->toBe(0)
        ->and(fn () => Decimal::of('1e3'))->toThrow(InvalidArgumentException::class);
});
