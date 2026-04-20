<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('renders the TransactionModal exactly once on the calendar page', function () {
    $response = $this->get(route('calendar'));

    $response->assertOk();
    $response->assertSeeLivewire('transaction-modal');

    expect(countLivewireMounts($response->getContent(), 'transaction-modal'))
        ->toBe(1);
});

/**
 * Count how many times a Livewire component is mounted in rendered HTML by
 * parsing each `wire:snapshot` attribute, decoding HTML entities, and inspecting
 * the snapshot's `memo.name`. More resilient than raw substring matching, which
 * would break if Livewire changes how it encodes snapshot JSON.
 */
function countLivewireMounts(string $html, string $componentName): int
{
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    $count = 0;

    foreach ($matches[1] as $encoded) {
        $decoded = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5);
        $snapshot = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);

        if (is_array($snapshot) && ($snapshot['memo']['name'] ?? null) === $componentName) {
            $count++;
        }
    }

    return $count;
}
