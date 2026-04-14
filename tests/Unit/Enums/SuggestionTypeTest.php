<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\SuggestionType;

test('all suggestion type cases exist', function () {
    expect(SuggestionType::cases())->toHaveCount(4);
});

test('suggestion type has correct backing values', function () {
    expect(SuggestionType::PrimaryAccount->value)->toBe('primary-account')
        ->and(SuggestionType::PayCycle->value)->toBe('pay-cycle')
        ->and(SuggestionType::RecurringTransaction->value)->toBe('recurring-transaction')
        ->and(SuggestionType::UserRule->value)->toBe('user-rule');
});

test('suggestion type resolves from backing value', function () {
    expect(SuggestionType::from('primary-account'))->toBe(SuggestionType::PrimaryAccount)
        ->and(SuggestionType::from('pay-cycle'))->toBe(SuggestionType::PayCycle)
        ->and(SuggestionType::from('recurring-transaction'))->toBe(SuggestionType::RecurringTransaction)
        ->and(SuggestionType::from('user-rule'))->toBe(SuggestionType::UserRule);
});

test('suggestion type has labels', function () {
    expect(SuggestionType::PrimaryAccount->label())->toBe('Primary Account')
        ->and(SuggestionType::PayCycle->label())->toBe('Pay Cycle')
        ->and(SuggestionType::RecurringTransaction->label())->toBe('Recurring Transaction')
        ->and(SuggestionType::UserRule->label())->toBe('User Rule');
});
