<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Exceptions\Statement\StatementHasPdfImports;
use App\Models\PdfImport;
use App\Models\PdfImportLine;
use App\Models\Statement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

it('refuses to delete a statement that still has pdf imports', function () {
    Storage::fake('local');
    Storage::disk('local')->put('statements/keep.pdf', '%PDF');
    $statement = Statement::factory()->create(['stored_path' => 'statements/keep.pdf']);
    PdfImport::factory()->create(['user_id' => $statement->user_id, 'statement_id' => $statement->id]);

    expect(fn () => $statement->delete())->toThrow(StatementHasPdfImports::class);

    expect(Statement::query()->whereKey($statement->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists('statements/keep.pdf'))->toBeTrue();
});

it('keeps the statement and its file when a pdf import is deleted', function () {
    Storage::fake('local');
    Storage::disk('local')->put('statements/keep.pdf', '%PDF');
    $statement = Statement::factory()->create(['stored_path' => 'statements/keep.pdf']);
    $import = PdfImport::factory()->create(['user_id' => $statement->user_id, 'statement_id' => $statement->id]);
    PdfImportLine::factory()->for($import)->count(2)->create();

    $import->delete();

    expect(Statement::query()->whereKey($statement->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists('statements/keep.pdf'))->toBeTrue()
        ->and(PdfImportLine::query()->count())->toBe(0);

    $statement->delete();

    expect(Storage::disk('local')->exists('statements/keep.pdf'))->toBeFalse();
});

it('stores line amounts as integer cents and keeps unreadable dates', function () {
    $line = PdfImportLine::factory()->unreadableDate()->create(['amount' => -12345]);

    $stored = DB::table('pdf_import_lines')->where('id', $line->id)->first();

    expect($stored->amount)->toBe(-12345)
        ->and($line->fresh()->date_unreadable)->toBeTrue()
        ->and($line->fresh()->line_date)->toBeNull();
});

it('scopes imports to their owner and removes them with the user', function () {
    $user = User::factory()->create();
    $mine = PdfImport::factory()->create(['user_id' => $user->id, 'statement_id' => Statement::factory()->for($user)]);
    PdfImport::factory()->create();

    expect(PdfImport::query()->forUser($user)->pluck('id')->all())->toBe([$mine->id]);

    $user->delete();

    expect(PdfImport::query()->count())->toBe(1);
});
