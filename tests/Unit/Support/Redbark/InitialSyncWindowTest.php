<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Support\Redbark\InitialSyncWindow;
use Carbon\CarbonImmutable;

test('the window starts on the first day of the previous calendar month', function (string $now, string $expected) {
    expect(InitialSyncWindow::start(CarbonImmutable::parse($now))->toDateTimeString())->toBe($expected);
})->with([
    'mid-month' => ['2026-09-27 15:30:00', '2026-08-01 00:00:00'],
    'across a year boundary' => ['2026-01-15 09:00:00', '2025-12-01 00:00:00'],
    'month end does not overflow' => ['2026-03-31 23:59:59', '2026-02-01 00:00:00'],
]);
