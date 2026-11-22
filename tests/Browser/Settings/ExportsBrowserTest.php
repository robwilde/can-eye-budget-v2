<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\DataExportStatus;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

test('the exports page lists exports, marks a stuck one failed and deletes a completed one', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    Storage::disk('local')->put('exports/'.$user->id.'/1.zip', 'zip bytes');
    $done = DataExport::factory()->for($user)->create(['status' => DataExportStatus::Completed, 'stored_path' => 'exports/'.$user->id.'/1.zip', 'byte_size' => 9]);
    $stuck = DataExport::factory()->for($user)->processingSince(now()->subHours(2))->create();

    $this->actingAs($user);

    $page = visit('/settings/exports');

    $page->assertSee('Exports')
        ->assertVisible('[data-test="download-export-button"]')
        ->assertVisible('[data-test="select-export-button"]')
        ->assertNoJavaScriptErrors()
        ->click('[data-test="select-export-button"]')
        ->click('[data-test="mark-export-failed-button"]')
        ->wait(1)
        ->assertSee('Marked failed by the user.')
        ->assertNoJavaScriptErrors()
        ->click('[wire\\:key="export-'.$done->id.'"] [data-test="delete-export-button"]')
        ->click('[data-test="confirm-delete-export-button"]')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect($stuck->fresh()->status)->toBe(DataExportStatus::Failed)
        ->and(DataExport::query()->count())->toBe(1);
});
