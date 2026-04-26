<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Services\CsvImport\CsvColumnMapper;

test('westpac headers map cleanly with entered date preferred over effective date', function () {
    $mapper = new CsvColumnMapper();
    $headers = ['Effective Date', 'Entered Date', 'Transaction Description', 'Amount', 'Balance'];

    $mapping = $mapper->suggest($headers);

    expect($mapping[CsvColumnMapper::FIELD_DATE])->toBe('Entered Date')
        ->and($mapping[CsvColumnMapper::FIELD_DESCRIPTION])->toBe('Transaction Description')
        ->and($mapping[CsvColumnMapper::FIELD_AMOUNT])->toBe('Amount')
        ->and($mapping[CsvColumnMapper::FIELD_BALANCE])->toBe('Balance');
});

test('cba style headers with Bank Date map to date', function () {
    $mapper = new CsvColumnMapper();
    $headers = ['Bank Date', 'Description', 'Debit', 'Credit', 'Balance'];

    $mapping = $mapper->suggest($headers);

    expect($mapping[CsvColumnMapper::FIELD_DATE])->toBe('Bank Date')
        ->and($mapping[CsvColumnMapper::FIELD_DESCRIPTION])->toBe('Description')
        ->and($mapping[CsvColumnMapper::FIELD_DEBIT])->toBe('Debit')
        ->and($mapping[CsvColumnMapper::FIELD_CREDIT])->toBe('Credit')
        ->and($mapping[CsvColumnMapper::FIELD_BALANCE])->toBe('Balance');
});

test('case insensitive matching works', function () {
    $mapper = new CsvColumnMapper();
    $headers = ['DATE', 'DESCRIPTION', 'AMOUNT'];

    $mapping = $mapper->suggest($headers);

    expect($mapping[CsvColumnMapper::FIELD_DATE])->toBe('DATE')
        ->and($mapping[CsvColumnMapper::FIELD_DESCRIPTION])->toBe('DESCRIPTION')
        ->and($mapping[CsvColumnMapper::FIELD_AMOUNT])->toBe('AMOUNT');
});

test('a header is consumed only once', function () {
    $mapper = new CsvColumnMapper();
    $headers = ['Description', 'Reference'];

    $mapping = $mapper->suggest($headers);

    expect($mapping[CsvColumnMapper::FIELD_DESCRIPTION])->toBe('Description');
    expect($mapping[CsvColumnMapper::FIELD_DESCRIPTION])->not->toBe($mapping[CsvColumnMapper::FIELD_DEBIT]);
});

test('unknown headers map to null', function () {
    $mapper = new CsvColumnMapper();
    $headers = ['SomeWeirdColumn', 'AnotherOne'];

    $mapping = $mapper->suggest($headers);

    expect($mapping[CsvColumnMapper::FIELD_DATE])->toBeNull()
        ->and($mapping[CsvColumnMapper::FIELD_AMOUNT])->toBeNull();
});

test('debit / credit headers do not collide with balance', function () {
    $mapper = new CsvColumnMapper();
    $headers = ['Date', 'Description', 'Money Out', 'Money In', 'Running Balance'];

    $mapping = $mapper->suggest($headers);

    expect($mapping[CsvColumnMapper::FIELD_DEBIT])->toBe('Money Out')
        ->and($mapping[CsvColumnMapper::FIELD_CREDIT])->toBe('Money In')
        ->and($mapping[CsvColumnMapper::FIELD_BALANCE])->toBe('Running Balance');
});
