<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('renders the dashboard with the transaction modal mounted', function () {
    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSeeLivewire('transaction-modal');
});
