<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionType;

test('all transaction type cases exist', function () {
    expect(TransactionType::cases())->toHaveCount(3);
});

test('transaction type has correct backing values', function () {
    expect(TransactionType::Expense->value)->toBe('expense')
        ->and(TransactionType::Income->value)->toBe('income')
        ->and(TransactionType::Transfer->value)->toBe('transfer');
});

test('transaction type resolves from backing value', function () {
    expect(TransactionType::from('expense'))->toBe(TransactionType::Expense)
        ->and(TransactionType::from('income'))->toBe(TransactionType::Income)
        ->and(TransactionType::from('transfer'))->toBe(TransactionType::Transfer);
});

test('transaction type has labels', function () {
    expect(TransactionType::Expense->label())->toBe('Expense')
        ->and(TransactionType::Income->label())->toBe('Income')
        ->and(TransactionType::Transfer->label())->toBe('Transfer');
});
