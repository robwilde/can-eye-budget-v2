<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('mounts the reconciliation modal on the dashboard page', function () {
    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSeeLivewire('reconciliation-modal');
});
