<?php

declare(strict_types=1);

use App\DTOs\RedbarkBalanceData;

test('from maps all fields', function () {
    $dto = RedbarkBalanceData::from([
        'accountId' => 'acc-1',
        'currentBalance' => '123.45',
        'availableBalance' => '100.00',
        'currency' => 'AUD',
    ]);

    expect($dto)
        ->accountId->toBe('acc-1')
        ->currentBalance->toBe('123.45')
        ->availableBalance->toBe('100.00')
        ->currency->toBe('AUD');
});

test('from coerces an explicit null accountId to an empty string', function () {
    $dto = RedbarkBalanceData::from([
        'accountId' => null,
        'currentBalance' => '123.45',
    ]);

    expect($dto->accountId)->toBe('');
});

test('from handles omitted accountId as an empty string', function () {
    $dto = RedbarkBalanceData::from([]);

    expect($dto->accountId)->toBe('');
});
