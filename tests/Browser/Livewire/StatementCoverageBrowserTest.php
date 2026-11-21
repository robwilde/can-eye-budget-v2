<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\Statement;
use App\Models\User;

test('the account statements tab shows the coverage grid and linked statements', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $month = now();
    Statement::factory()->for($user)->create([
        'account_id' => $account->id,
        'original_filename' => 'recent-statement.pdf',
        'period_start' => $month->copy()->startOfMonth()->toDateString(),
        'period_end' => $month->copy()->endOfMonth()->toDateString(),
    ]);

    $this->actingAs($user);

    visit('/accounts/'.$account->id.'?tab=statements')
        ->assertSee('Statement coverage')
        ->assertSee('Covered')
        ->assertSee('recent-statement.pdf')
        ->assertNoJavaScriptErrors();
});
