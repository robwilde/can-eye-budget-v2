<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\User;

test('the bill audit shows on the rules page and refuses a bad look back', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    $this->actingAs($user);

    visit('/rules')
        ->assertVisible('[data-testid="bill-audit"]')
        ->assertSee('Possible duplicates')
        ->fill('[data-testid="audit-lookback"]', '40')
        ->wait(2)
        ->assertVisible('[data-testid="audit-lookback-error"]')
        ->assertNoJavaScriptErrors();
});
