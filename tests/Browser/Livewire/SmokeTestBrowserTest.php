<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\User;

test('smoke test component renders and increments counter', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/smoke-test');

    $page->assertSee('Smoke Test')
        ->assertSee('Count: 0')
        ->click('Increment')
        ->assertSee('Count: 1');
});
