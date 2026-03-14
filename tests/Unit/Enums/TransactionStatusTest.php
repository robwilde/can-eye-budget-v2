<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionStatus;

test('all transaction status cases exist', function () {
    expect(TransactionStatus::cases())->toHaveCount(2);
});

test('transaction status has correct backing values', function () {
    expect(TransactionStatus::Posted->value)->toBe('posted')
        ->and(TransactionStatus::Pending->value)->toBe('pending');
});

test('transaction status resolves from backing value', function () {
    expect(TransactionStatus::from('posted'))->toBe(TransactionStatus::Posted)
        ->and(TransactionStatus::from('pending'))->toBe(TransactionStatus::Pending);
});
