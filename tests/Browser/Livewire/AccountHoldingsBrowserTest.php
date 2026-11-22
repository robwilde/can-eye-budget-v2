<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\Security;
use App\Models\Trade;
use App\Models\User;

test('the holdings tab records a trade and shows the holding when the flag is on', function () {
    config(['budget.investments.enabled' => true]);
    $user = User::factory()->create();
    $account = Account::factory()->investment()->for($user)->create(['name' => 'Brokerage']);
    Security::factory()->create(['ticker' => 'VAS', 'exchange_mic' => 'XASX', 'currency' => 'AUD']);

    $this->actingAs($user);

    visit('/accounts/'.$account->id.'?tab=holdings')
        ->assertSee('Record a trade')
        ->assertSee('No holdings yet')
        ->assertNoJavaScriptErrors();

    $security = Security::query()->where('ticker', 'VAS')->sole();

    $page = visit('/accounts/'.$account->id.'?tab=holdings')
        ->fill('[data-test="trade-ticker-search"]', 'VAS')
        ->wait(1.5)
        ->select('[data-test="trade-security"]', 'id:'.$security->id)
        ->fill('[data-test="trade-quantity"]', '10')
        ->fill('[data-test="trade-price"]', '15')
        ->fill('[data-test="trade-fee"]', '5')
        ->click('[data-test="trade-submit"]')
        ->assertSee('Recorded.')
        ->assertSee('VAS')
        ->assertNoJavaScriptErrors();

    expect(Trade::query()->sole()->amount)->toBe(15500);

    $page->click('[data-test="holding-detail-open"]')->assertSee('Market price')->assertSee('Book value');
});

test('the holdings tab is hidden when the flag is off', function () {
    config(['budget.investments.enabled' => false]);
    $user = User::factory()->create();
    $account = Account::factory()->investment()->for($user)->create();

    $this->actingAs($user);

    visit('/accounts/'.$account->id.'?tab=holdings')
        ->assertDontSee('Record a trade')
        ->assertDontSee('Holdings')
        ->assertNoJavaScriptErrors();
});
