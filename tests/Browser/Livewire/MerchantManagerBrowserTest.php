<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\User;

test('the merchants page creates a merchant and filters the list by search', function () {
    $user = User::factory()->create();
    $a = Merchant::factory()->for($user)->create(['name' => 'Woolies Bondi']);
    Merchant::factory()->for($user)->create(['name' => 'Woolworths Metro']);
    Transaction::factory()->count(2)->for($user)->create(['merchant_id' => $a->id]);
    $this->actingAs($user);

    $page = visit('/merchants');

    $page->assertSee('Your merchants')
        ->assertSee('Woolies Bondi')
        ->fill('[data-testid="merchant-name"]', 'Corner Cafe')
        ->click('[data-testid="merchant-save"]')
        ->assertSee('Corner Cafe')
        ->fill('[data-testid="merchant-search"]', 'Metro')
        ->assertSee('Woolworths Metro')
        ->assertDontSee('Corner Cafe')
        ->assertNoJavaScriptErrors();

    expect(Merchant::query()->where('user_id', $user->id)->where('name', 'Corner Cafe')->exists())->toBeTrue();
});
