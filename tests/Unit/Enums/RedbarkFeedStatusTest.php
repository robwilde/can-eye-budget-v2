<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RedbarkFeedStatus;

test('all redbark feed status cases exist', function () {
    expect(RedbarkFeedStatus::cases())->toHaveCount(2);
});

test('redbark feed status has correct backing values', function () {
    expect(RedbarkFeedStatus::Good->value)->toBe('good')
        ->and(RedbarkFeedStatus::RequiresUpdate->value)->toBe('requires_update');
});

test('redbark feed status labels tell the user what to do', function () {
    expect(RedbarkFeedStatus::Good->label())->toBe('Connected')
        ->and(RedbarkFeedStatus::RequiresUpdate->label())->toBe('Connection needs update');
});
