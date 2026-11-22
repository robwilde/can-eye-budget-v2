<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RedbarkTransport;
use App\Models\RedbarkFeed;
use App\Models\User;

test('the providers page lists a feed per transport and disconnects one of them', function () {
    $user = User::factory()->create();
    RedbarkFeed::factory()->for($user)->synced()->create([
        'transport' => RedbarkTransport::SimpleFin,
        'api_key' => null,
        'simplefin_access_url' => 'https://sfuser:sfpass@api.redbark.com/simplefin',
    ]);
    $rest = RedbarkFeed::factory()->for($user)->synced()->create();

    $this->actingAs($user);

    $page = visit('/settings/providers');

    $page->assertSee('Redbark API key')
        ->assertSee('SimpleFIN')
        ->assertVisible('[data-test="redbark-feed-rest"]')
        ->assertVisible('[data-test="redbark-feed-simplefin"]')
        ->assertVisible('[data-test="connect-simplefin-button"]')
        ->assertNoJavaScriptErrors()
        ->click('[data-test="redbark-feed-rest"] [data-test="disconnect-redbark-button"]')
        ->click('[data-test="redbark-feed-rest"] [data-test="confirm-disconnect-redbark-button"]')
        ->assertMissing('[data-test="redbark-feed-rest"]')
        ->assertVisible('[data-test="redbark-feed-simplefin"]')
        ->assertNoJavaScriptErrors();

    expect(RedbarkFeed::query()->whereKey($rest->id)->exists())->toBeFalse()
        ->and(RedbarkFeed::query()->where('user_id', $user->id)->count())->toBe(1);
});
