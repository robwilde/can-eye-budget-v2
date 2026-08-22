<?php

declare(strict_types=1);

use App\DTOs\RedbarkAccountData;

test('from maps all fields', function () {
    $dto = RedbarkAccountData::from([
        'id' => 'acc-1',
        'connectionId' => 'conn-1',
        'provider' => 'redbark',
        'name' => 'Everyday Saver',
        'type' => 'savings',
        'institutionName' => 'Big Bank',
        'accountNumber' => '123456',
        'currency' => 'AUD',
    ]);

    expect($dto)
        ->id->toBe('acc-1')
        ->connectionId->toBe('conn-1')
        ->provider->toBe('redbark')
        ->name->toBe('Everyday Saver')
        ->type->toBe('savings')
        ->institutionName->toBe('Big Bank')
        ->accountNumber->toBe('123456')
        ->currency->toBe('AUD');
});

test('from coerces an explicit null id to an empty string', function () {
    $dto = RedbarkAccountData::from([
        'id' => null,
        'name' => 'Everyday Saver',
    ]);

    expect($dto->id)->toBe('');
});

test('from coerces an explicit null name to an empty string', function () {
    $dto = RedbarkAccountData::from([
        'id' => 'acc-1',
        'name' => null,
    ]);

    expect($dto->name)->toBe('');
});

test('from handles omitted id and name as empty strings', function () {
    $dto = RedbarkAccountData::from([]);

    expect($dto)
        ->id->toBe('')
        ->name->toBe('');
});
