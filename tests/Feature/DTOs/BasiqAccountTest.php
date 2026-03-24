<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\BasiqAccount;

test('from maps all fields including nested class.type', function () {
    $dto = BasiqAccount::from([
        'id' => 'acc-1',
        'name' => 'Everyday Saver',
        'institution' => 'AU00000',
        'class' => ['type' => 'savings'],
        'balance' => '12345',
        'currency' => 'AUD',
        'status' => 'active',
    ]);

    expect($dto)
        ->id->toBe('acc-1')
        ->name->toBe('Everyday Saver')
        ->institution->toBe('AU00000')
        ->type->toBe('savings')
        ->balance->toBe('12345')
        ->currency->toBe('AUD')
        ->status->toBe('active');
});

test('from falls back to top-level type when class is missing', function () {
    $dto = BasiqAccount::from([
        'id' => 'acc-2',
        'name' => 'Credit Card',
        'type' => 'credit',
        'currency' => 'AUD',
    ]);

    expect($dto)
        ->type->toBe('credit')
        ->institution->toBeNull()
        ->balance->toBeNull()
        ->status->toBeNull();
});

test('from handles all optional fields as null', function () {
    $dto = BasiqAccount::from([
        'id' => 'acc-3',
        'name' => 'Bare Account',
        'currency' => 'USD',
    ]);

    expect($dto)
        ->id->toBe('acc-3')
        ->name->toBe('Bare Account')
        ->institution->toBeNull()
        ->type->toBeNull()
        ->balance->toBeNull()
        ->currency->toBe('USD')
        ->status->toBeNull()
        ->creditLimit->toBeNull()
        ->availableFunds->toBeNull();
});

test('from maps creditLimit and availableFunds', function () {
    $dto = BasiqAccount::from([
        'id' => 'acc-4',
        'name' => 'Credit Card',
        'institution' => 'AU00000',
        'class' => ['type' => 'credit-card'],
        'balance' => '-3503.41',
        'currency' => 'AUD',
        'creditLimit' => '70581.58',
        'availableFunds' => '-3503.41',
        'status' => 'available',
    ]);

    expect($dto)
        ->creditLimit->toBe('70581.58')
        ->availableFunds->toBe('-3503.41');
});

test('from normalizes empty string creditLimit and availableFunds to null', function () {
    $dto = BasiqAccount::from([
        'id' => 'acc-5',
        'name' => 'Cheque Account',
        'currency' => 'AUD',
        'creditLimit' => '',
        'availableFunds' => '',
    ]);

    expect($dto)
        ->creditLimit->toBeNull()
        ->availableFunds->toBeNull();
});
