<?php

/** @noinspection JSUnresolvedReference */
/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\User;

test('currency options follow the account chosen in the transaction modal', function () {
    $user = User::factory()->create();
    $euro = Account::factory()->for($user)->create(['currency' => 'EUR']);

    $this->actingAs($user);

    $page = visit('/calendar');

    $page->script("Livewire.dispatch('open-transaction-modal', { date: '2026-04-19' })");

    $currencyOptions = <<<'JS'
        [...document.querySelectorAll('[data-testid="currency-fields"] select option')].map(option => option.value)
    JS;

    $page->assertPresent('[data-testid="currency-fields"]');

    expect($page->script($currencyOptions))->toContain('EUR');

    $page->script(<<<JS
        (() => {
            const select = [...document.querySelectorAll('select')]
                .find(candidate => [...candidate.options].some(option => option.textContent.includes('Select account')));
            select.value = '{$euro->id}';
            select.dispatchEvent(new Event('change', { bubbles: true }));
        })()
    JS);

    $page->wait(1);

    expect($page->script($currencyOptions))->not->toContain('EUR');
});
