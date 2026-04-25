<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionSource;

test('all transaction source cases exist', function () {
    expect(TransactionSource::cases())->toHaveCount(4);
});

test('transaction source has correct backing values', function () {
    expect(TransactionSource::Manual->value)->toBe('manual')
        ->and(TransactionSource::Basiq->value)->toBe('basiq')
        ->and(TransactionSource::Planned->value)->toBe('planned')
        ->and(TransactionSource::Csv->value)->toBe('csv');
});

test('transaction source resolves from backing value', function () {
    expect(TransactionSource::from('manual'))->toBe(TransactionSource::Manual)
        ->and(TransactionSource::from('basiq'))->toBe(TransactionSource::Basiq)
        ->and(TransactionSource::from('planned'))->toBe(TransactionSource::Planned)
        ->and(TransactionSource::from('csv'))->toBe(TransactionSource::Csv);
});

test('transaction source has labels', function () {
    expect(TransactionSource::Manual->label())->toBe('Manual')
        ->and(TransactionSource::Basiq->label())->toBe('Basiq')
        ->and(TransactionSource::Planned->label())->toBe('Planned')
        ->and(TransactionSource::Csv->label())->toBe('CSV import');
});
