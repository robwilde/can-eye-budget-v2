<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PdfImportStatus;
use App\Models\Account;
use App\Models\PdfImport;
use App\Models\PdfImportLine;
use App\Models\Statement;
use App\Models\User;

test('the review page shows lines and publishes the import', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Everyday']);
    $statement = Statement::factory()->for($user)->create(['original_filename' => 'march.pdf']);
    $import = PdfImport::factory()->ready()->create(['user_id' => $user->id, 'statement_id' => $statement->id, 'account_id' => $account->id]);
    PdfImportLine::factory()->for($import)->create(['description' => 'Corner Cafe', 'line_date' => now()->toDateString(), 'amount' => -450]);

    $this->actingAs($user);

    visit('/imports/pdf/'.$import->id)
        ->assertSee('Review PDF import')
        ->assertSee('Corner Cafe')
        ->assertSee('To import')
        ->click('Publish')
        ->assertSee('Revert')
        ->assertNoJavaScriptErrors();

    expect($import->fresh()->status)->toBe(PdfImportStatus::Published);
});
