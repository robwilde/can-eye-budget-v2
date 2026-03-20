<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\BasiqTransaction;

test('from maps all fields with enrich data', function () {
    $enrich = [
        'merchant' => ['businessName' => 'Woolworths'],
        'category' => ['anzsic' => ['code' => '4111']],
    ];

    $dto = BasiqTransaction::from([
        'id' => 'txn-1',
        'amount' => '-42.50',
        'direction' => 'debit',
        'description' => 'WOOLWORTHS 1234',
        'postDate' => '2026-03-10',
        'account' => 'acc-1',
        'status' => 'posted',
        'transactionDate' => '2026-03-09',
        'enrich' => $enrich,
    ]);

    expect($dto)
        ->id->toBe('txn-1')
        ->amount->toBe('-42.50')
        ->direction->toBe('debit')
        ->description->toBe('WOOLWORTHS 1234')
        ->postDate->toBe('2026-03-10')
        ->account->toBe('acc-1')
        ->status->toBe('posted')
        ->transactionDate->toBe('2026-03-09')
        ->merchant->toBe('Woolworths')
        ->anzsic->toBe('4111')
        ->enrichData->toBe($enrich);
});

test('from handles missing enrich data', function () {
    $dto = BasiqTransaction::from([
        'id' => 'txn-2',
        'amount' => '100.00',
        'direction' => 'credit',
    ]);

    expect($dto)
        ->id->toBe('txn-2')
        ->amount->toBe('100.00')
        ->direction->toBe('credit')
        ->description->toBeNull()
        ->postDate->toBeNull()
        ->account->toBeNull()
        ->status->toBeNull()
        ->transactionDate->toBeNull()
        ->merchant->toBeNull()
        ->anzsic->toBeNull()
        ->enrichData->toBeNull();
});

test('from handles partial enrich data', function () {
    $dto = BasiqTransaction::from([
        'id' => 'txn-3',
        'amount' => '55.00',
        'direction' => 'debit',
        'enrich' => [
            'merchant' => ['businessName' => 'Coles'],
        ],
    ]);

    expect($dto)
        ->merchant->toBe('Coles')
        ->anzsic->toBeNull()
        ->enrichData->toBeArray();
});
