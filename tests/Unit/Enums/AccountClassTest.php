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
