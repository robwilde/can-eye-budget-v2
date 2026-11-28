<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PdfImportStatus;
use App\Exceptions\Statement\PdfImportRefused;
use App\Jobs\ExtractStatementPdfJob;
use App\Models\Account;
use App\Models\PdfImport;
use App\Models\Statement;
use App\Models\User;
use App\Services\Statement\PdfImportStarter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Services\Statement\StatementPdfFactory;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    config(['budget.pdf_import.enabled' => true]);
    $this->user = User::factory()->create();
    $this->starter = app(PdfImportStarter::class);
});

function starterPdf(string $name = 'march.pdf', array $lines = ['Statement']): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, StatementPdfFactory::make($lines));
}

it('files the pdf in the vault and queues the extraction', function () {
    $account = Account::factory()->for($this->user)->create();

    $import = $this->starter->start($this->user, starterPdf(), $account, 'd/m/Y');

    expect($import->status)->toBe(PdfImportStatus::Pending)
        ->and($import->user_id)->toBe($this->user->id)
        ->and($import->account_id)->toBe($account->id)
        ->and($import->date_format)->toBe('d/m/Y')
        ->and($import->statement->original_filename)->toBe('march.pdf')
        ->and(Statement::query()->count())->toBe(1);

    Queue::assertPushed(ExtractStatementPdfJob::class, fn (ExtractStatementPdfJob $job): bool => $job->import->is($import));
});

it('reuses the statement and an unfailed import when the same pdf is uploaded again', function () {
    $first = $this->starter->start($this->user, starterPdf('a.pdf'));
    $first->update(['status' => PdfImportStatus::Ready]);

    $second = $this->starter->start($this->user, starterPdf('b.pdf'));

    expect($second->is($first))->toBeTrue()
        ->and(Statement::query()->count())->toBe(1)
        ->and(PdfImport::query()->count())->toBe(1);

    Queue::assertPushedTimes(ExtractStatementPdfJob::class, 1);
});

it('starts a new import for the same statement when the account or date format changes or the last one failed', function () {
    $first = $this->starter->start($this->user, starterPdf());
    $account = Account::factory()->for($this->user)->create();

    $otherAccount = $this->starter->start($this->user, starterPdf(), $account);
    $otherFormat = $this->starter->start($this->user, starterPdf(), null, 'Y-m-d');
    $first->update(['status' => PdfImportStatus::Failed]);
    $afterFailure = $this->starter->start($this->user, starterPdf());

    expect(collect([$first, $otherAccount, $otherFormat, $afterFailure])->pluck('id')->unique())->toHaveCount(4)
        ->and(Statement::query()->count())->toBe(1);
});

it('reuses a statement that is already in the vault', function () {
    $content = StatementPdfFactory::make(['Statement']);
    $existing = app(App\Services\Statement\StatementUploadService::class)
        ->upload($this->user, [UploadedFile::fake()->createWithContent('vault.pdf', $content)])->created[0];

    $import = $this->starter->start($this->user, UploadedFile::fake()->createWithContent('again.pdf', $content));

    expect($import->statement_id)->toBe($existing->id)
        ->and(Statement::query()->count())->toBe(1);
});

it('stores and imports the user\'s own copy of a pdf another user already stored', function () {
    $content = StatementPdfFactory::make(['Statement']);
    $theirs = Statement::factory()->create(['content_hash' => hash('sha256', $content)]);
    $theirsBefore = $theirs->fresh()->getAttributes();

    $import = $this->starter->start($this->user, UploadedFile::fake()->createWithContent('mine.pdf', $content));

    expect($import->status)->toBe(PdfImportStatus::Pending)
        ->and($import->user_id)->toBe($this->user->id)
        ->and($import->statement->user_id)->toBe($this->user->id)
        ->and($import->statement->content_hash)->toBe($theirs->content_hash)
        ->and($import->statement_id)->not->toBe($theirs->id)
        ->and($theirs->fresh()->getAttributes())->toBe($theirsBefore)
        ->and(Statement::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and(Statement::query()->count())->toBe(2)
        ->and(PdfImport::query()->where('statement_id', $theirs->id)->count())->toBe(0);

    Queue::assertPushed(ExtractStatementPdfJob::class, fn (ExtractStatementPdfJob $job): bool => $job->import->is($import));
});

it('refuses when the feature is off, the file is not a pdf or the account is not the user\'s', function () {
    $foreign = Account::factory()->for(User::factory()->create())->create();
    $csv = UploadedFile::fake()->createWithContent('march.csv', "Date,Amount\n2026-03-01,1.00\n");

    expect(fn () => $this->starter->start($this->user, starterPdf(), $foreign))->toThrow(PdfImportRefused::class)
        ->and(fn () => $this->starter->start($this->user, $csv))->toThrow(PdfImportRefused::class)
        ->and(fn () => $this->starter->start($this->user, starterPdf(), null, 'bad'))->toThrow(PdfImportRefused::class);

    config(['budget.pdf_import.enabled' => false]);

    expect(fn () => $this->starter->start($this->user, starterPdf()))->toThrow(PdfImportRefused::class, 'PDF import is not enabled.');
    Queue::assertNothingPushed();
});

it('does not file a non-pdf file in the vault before refusing it', function () {
    $csv = UploadedFile::fake()->createWithContent('march.csv', "Date,Amount\n2026-03-01,1.00\n");

    expect(fn () => $this->starter->start($this->user, $csv))->toThrow(PdfImportRefused::class, 'Only PDF files can be imported.');

    expect(Statement::query()->count())->toBe(0);
});

it('refuses a csv that is already stored as a statement', function () {
    $content = "Date,Amount\n2026-03-01,1.00\n";
    Statement::factory()->for($this->user)->create(['content_hash' => hash('sha256', $content), 'file_type' => 'csv']);

    expect(fn () => $this->starter->start($this->user, UploadedFile::fake()->createWithContent('march.pdf', $content)))
        ->toThrow(PdfImportRefused::class, 'Only PDF files can be imported.');
});
