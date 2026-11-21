<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\StatementReviewStatus;
use App\Models\Account;
use App\Models\Statement;
use App\Models\User;

test('the vault lists the inbox and links a suggested statement', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Everyday']);
    $statement = Statement::factory()->for($user)->create([
        'original_filename' => 'march-statement.pdf',
        'suggested_account_id' => $account->id,
        'suggestion_confidence' => '0.7500',
        'byte_size' => 2 * 1024 * 1024,
    ]);

    $this->actingAs($user);

    $page = visit('/statements');

    $page->assertSee('Statements')
        ->assertSee('march-statement.pdf')
        ->assertSee('Suggested: Everyday (75%)')
        ->assertSee('Storage used: 2 MB')
        ->click('Accept')
        ->assertSee('No statements are waiting to be linked.')
        ->assertSee('Linked statements')
        ->assertSee('march-statement.pdf')
        ->assertNoJavaScriptErrors();

    expect($statement->fresh()->review_status)->toBe(StatementReviewStatus::Linked);
});

test('the vault links an inbox statement to the account picked in its select', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Everyday']);
    $statement = Statement::factory()->for($user)->create(['original_filename' => 'april-statement.pdf']);

    $this->actingAs($user);

    visit('/statements')
        ->select('[data-test="statement-link-select-'.$statement->id.'"]', (string) $account->id)
        ->click('[data-test="statement-link-'.$statement->id.'"]')
        ->assertSee('No statements are waiting to be linked.')
        ->assertNoJavaScriptErrors();

    expect($statement->fresh()->account_id)->toBe($account->id);
});
