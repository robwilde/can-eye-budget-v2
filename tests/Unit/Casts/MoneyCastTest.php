<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Casts\MoneyCast;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;

test('get returns integer from string database value', function () {
    $cast = new MoneyCast;
    $model = new class extends Model {};

    expect($cast->get($model, 'amount', '4599', []))->toBe(4599);
});

test('get preserves null for nullable columns', function () {
    $cast = new MoneyCast;
    $model = new class extends Model {};

    expect($cast->get($model, 'amount', null, []))->toBeNull();
});

test('set stores integer from int input', function () {
    $cast = new MoneyCast;
    $model = new class extends Model {};

    expect($cast->set($model, 'amount', 4599, []))->toBe(4599);
});

test('format formats positive amount', function () {
    expect(MoneyCast::format(4599))->toBe('$45.99');
});

test('format formats zero', function () {
    expect(MoneyCast::format(0))->toBe('$0.00');
});

test('format formats negative amount', function () {
    expect(MoneyCast::format(-150075))->toBe('-$1,500.75');
});

test('format formats large amount with thousand separators', function () {
    expect(MoneyCast::format(1234567))->toBe('$12,345.67');
});

test('format pads single-digit cents', function () {
    expect(MoneyCast::format(1205))->toBe('$12.05');
});

test('account balance uses MoneyCast', function () {
    $casts = (new Account)->getCasts();

    expect($casts['balance'])->toBe(MoneyCast::class);
});

test('transaction amount uses MoneyCast', function () {
    $casts = (new Transaction)->getCasts();

    expect($casts['amount'])->toBe(MoneyCast::class);
});

test('budget limit_amount uses MoneyCast', function () {
    $casts = (new Budget)->getCasts();

    expect($casts['limit_amount'])->toBe(MoneyCast::class);
});
