<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\SuggestionStatus;

test('all suggestion status cases exist', function () {
    expect(SuggestionStatus::cases())->toHaveCount(4);
});

test('suggestion status has correct backing values', function () {
    expect(SuggestionStatus::Pending->value)->toBe('pending')
        ->and(SuggestionStatus::Accepted->value)->toBe('accepted')
        ->and(SuggestionStatus::Rejected->value)->toBe('rejected')
        ->and(SuggestionStatus::Superseded->value)->toBe('superseded');
});

test('suggestion status resolves from backing value', function () {
    expect(SuggestionStatus::from('pending'))->toBe(SuggestionStatus::Pending)
        ->and(SuggestionStatus::from('accepted'))->toBe(SuggestionStatus::Accepted)
        ->and(SuggestionStatus::from('rejected'))->toBe(SuggestionStatus::Rejected)
        ->and(SuggestionStatus::from('superseded'))->toBe(SuggestionStatus::Superseded);
});

test('suggestion status has labels', function () {
    expect(SuggestionStatus::Pending->label())->toBe('Pending')
        ->and(SuggestionStatus::Accepted->label())->toBe('Accepted')
        ->and(SuggestionStatus::Rejected->label())->toBe('Rejected')
        ->and(SuggestionStatus::Superseded->label())->toBe('Superseded');
});
