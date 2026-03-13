<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountStatus;

test('all account status cases exist', function () {
    expect(AccountStatus::cases())->toHaveCount(3);
});

test('account status has correct backing values', function () {
    expect(AccountStatus::Active->value)->toBe('active')
        ->and(AccountStatus::Inactive->value)->toBe('inactive')
        ->and(AccountStatus::Closed->value)->toBe('closed');
});
