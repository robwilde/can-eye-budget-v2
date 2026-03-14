<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;

test('all transaction direction cases exist', function () {
    expect(TransactionDirection::cases())->toHaveCount(2);
});

test('transaction direction has correct backing values', function () {
    expect(TransactionDirection::Debit->value)->toBe('debit')
        ->and(TransactionDirection::Credit->value)->toBe('credit');
});

test('transaction direction resolves from backing value', function () {
    expect(TransactionDirection::from('debit'))->toBe(TransactionDirection::Debit)
        ->and(TransactionDirection::from('credit'))->toBe(TransactionDirection::Credit);
});
