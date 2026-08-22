<?php

declare(strict_types=1);

use App\DTOs\RedbarkConnectionData;

test('from maps all fields', function () {
    $dto = RedbarkConnectionData::from([
        'id' => 'conn-1',
        'category' => 'bank',
        'institutionName' => 'Big Bank',
        'institutionLogo' => 'https://example.com/logo.png',
        'status' => 'active',
    ]);

    expect($dto)
        ->id->toBe('conn-1')
        ->category->toBe('bank')
        ->institutionName->toBe('Big Bank')
        ->institutionLogo->toBe('https://example.com/logo.png')
        ->status->toBe('active');
});

test('from coerces an explicit null id to an empty string', function () {
    $dto = RedbarkConnectionData::from([
        'id' => null,
        'category' => 'bank',
    ]);

    expect($dto->id)->toBe('');
});

test('from handles omitted id as an empty string', function () {
    $dto = RedbarkConnectionData::from([]);

    expect($dto->id)->toBe('');
});
