<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\User;

test('the recurring settings page offers the calendar feed when the flag is on', function () {
    config(['budget.calendar_feed' => true]);
    $user = User::factory()->create();

    $this->actingAs($user);

    visit('/settings/recurring')
        ->assertVisible('[data-testid="calendar-feed"]')
        ->assertVisible('[data-testid="calendar-feed-reset"]')
        ->screenshot(filename: 'calendar-feed-settings')
        ->assertNoJavaScriptErrors();
});
