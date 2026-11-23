<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PlannedTransactionType;
use App\Enums\TransactionDirection;

it('offers income and other for a credit and the bill types for a debit', function () {
    expect(PlannedTransactionType::selectableFor(TransactionDirection::Credit))
        ->toBe([PlannedTransactionType::Income, PlannedTransactionType::Other])
        ->and(PlannedTransactionType::selectableFor(TransactionDirection::Debit))
        ->toBe([PlannedTransactionType::Bill, PlannedTransactionType::Subscription, PlannedTransactionType::Installment, PlannedTransactionType::Other]);
});

it('never offers transfer as a choice', function (TransactionDirection $direction) {
    expect(PlannedTransactionType::selectableFor($direction))->not->toContain(PlannedTransactionType::Transfer);
})->with([TransactionDirection::Debit, TransactionDirection::Credit]);

it('resolves the saved type from the choice, direction and destination', function (?PlannedTransactionType $chosen, TransactionDirection $direction, bool $isTransfer, PlannedTransactionType $expected) {
    expect(PlannedTransactionType::resolve($chosen, $direction, $isTransfer))->toBe($expected);
})->with([
    'transfer wins' => [PlannedTransactionType::Bill, TransactionDirection::Debit, true, PlannedTransactionType::Transfer],
    'valid choice kept' => [PlannedTransactionType::Subscription, TransactionDirection::Debit, false, PlannedTransactionType::Subscription],
    'income choice on a debit falls back to bill' => [PlannedTransactionType::Income, TransactionDirection::Debit, false, PlannedTransactionType::Bill],
    'bill choice on a credit falls back to income' => [PlannedTransactionType::Bill, TransactionDirection::Credit, false, PlannedTransactionType::Income],
    'no choice on a credit is income' => [null, TransactionDirection::Credit, false, PlannedTransactionType::Income],
    'no choice on a debit is bill' => [null, TransactionDirection::Debit, false, PlannedTransactionType::Bill],
    'stale transfer choice without a destination' => [PlannedTransactionType::Transfer, TransactionDirection::Debit, false, PlannedTransactionType::Bill],
    'other on a credit kept' => [PlannedTransactionType::Other, TransactionDirection::Credit, false, PlannedTransactionType::Other],
]);
