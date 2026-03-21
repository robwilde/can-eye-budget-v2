<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountClass;

test('all account class cases exist', function () {
    expect(AccountClass::cases())->toHaveCount(9);
});

test('account class has correct backing values', function () {
    expect(AccountClass::Transaction->value)->toBe('transaction')
        ->and(AccountClass::Savings->value)->toBe('savings')
        ->and(AccountClass::CreditCard->value)->toBe('credit-card')
        ->and(AccountClass::Loan->value)->toBe('loan')
        ->and(AccountClass::Mortgage->value)->toBe('mortgage')
        ->and(AccountClass::Investment->value)->toBe('investment')
        ->and(AccountClass::Insurance->value)->toBe('insurance')
        ->and(AccountClass::Foreign->value)->toBe('foreign')
        ->and(AccountClass::TermDeposit->value)->toBe('term-deposit');
});

test('account class resolves from backing value', function () {
    expect(AccountClass::from('credit-card'))->toBe(AccountClass::CreditCard)
        ->and(AccountClass::from('term-deposit'))->toBe(AccountClass::TermDeposit);
});

test('label returns human-readable names', function () {
    expect(AccountClass::Transaction->label())->toBe('Transaction')
        ->and(AccountClass::Savings->label())->toBe('Savings')
        ->and(AccountClass::CreditCard->label())->toBe('Credit Card')
        ->and(AccountClass::Loan->label())->toBe('Loan')
        ->and(AccountClass::Mortgage->label())->toBe('Mortgage')
        ->and(AccountClass::Investment->label())->toBe('Investment')
        ->and(AccountClass::Insurance->label())->toBe('Insurance')
        ->and(AccountClass::Foreign->label())->toBe('Foreign')
        ->and(AccountClass::TermDeposit->label())->toBe('Term Deposit');
});

test('isAsset returns true for asset account types', function (AccountClass $type) {
    expect($type->isAsset())->toBeTrue();
})->with([
    'transaction' => AccountClass::Transaction,
    'savings' => AccountClass::Savings,
    'investment' => AccountClass::Investment,
    'insurance' => AccountClass::Insurance,
    'foreign' => AccountClass::Foreign,
    'term deposit' => AccountClass::TermDeposit,
]);

test('isAsset returns false for liability account types', function (AccountClass $type) {
    expect($type->isAsset())->toBeFalse();
})->with([
    'credit card' => AccountClass::CreditCard,
    'loan' => AccountClass::Loan,
    'mortgage' => AccountClass::Mortgage,
]);

test('icon returns a non-empty string for all cases', function (AccountClass $type) {
    expect($type->icon())->toBeString()->not->toBeEmpty();
})->with(AccountClass::cases());
