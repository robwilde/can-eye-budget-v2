<?php

declare(strict_types=1);

use App\DTOs\RedbarkTransactionData;

test('from maps all fields', function () {
    $dto = RedbarkTransactionData::from([
        'id' => 'txn-1',
        'accountId' => 'acc-1',
        'accountName' => 'Everyday Saver',
        'status' => 'posted',
        'date' => '2024-01-01',
        'postDate' => '2024-01-02',
        'valueDate' => '2024-01-01',
        'description' => 'Coffee',
        'amount' => '-4.50',
        'direction' => 'debit',
        'category' => 'food',
        'merchantName' => 'Cafe',
        'merchantCategoryCode' => '5812',
    ]);

    expect($dto)
        ->id->toBe('txn-1')
        ->accountId->toBe('acc-1')
        ->amount->toBe('-4.50');
});

test('from coerces an explicit null id to an empty string', function () {
    $dto = RedbarkTransactionData::from([
        'id' => null,
        'valueDate' => null,
        'merchantName' => null,
    ]);

    expect($dto)
        ->id->toBe('')
        ->valueDate->toBeNull()
        ->merchantName->toBeNull();
});

test('from handles omitted id as an empty string', function () {
    $dto = RedbarkTransactionData::from([]);

    expect($dto->id)->toBe('');
});
