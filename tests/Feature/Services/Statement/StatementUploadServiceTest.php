<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\StatementReviewStatus;
use App\Models\Account;
use App\Models\BankImport;
use App\Models\Statement;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Statement\StatementUploadService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Services\Statement\StatementPdfFactory;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->service = app(StatementUploadService::class);
});

function uploadedStatementCsv(string $name, ?string $content = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content ?? "Date,Description,Amount\n2026-03-03,Coffee,-4.50\n2026-03-28,Salary,2000.00\n");
}

function uploadedStatementPdf(string $name, array $lines = ['Statement']): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, StatementPdfFactory::make($lines));
}

it('skips a re-uploaded statement and counts it as a duplicate', function () {
    $first = $this->service->upload($this->user, [uploadedStatementCsv('march.csv')]);
    $second = $this->service->upload($this->user, [uploadedStatementCsv('march-again.csv')]);

    expect($first->created)->toHaveCount(1)
        ->and($second->created)->toBe([])
        ->and($second->duplicates)->toBe(1)
        ->and(Statement::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles('statements'))->toHaveCount(1);
});

it('counts a duplicate inside one batch and still files the rest', function () {
    $result = $this->service->upload($this->user, [
        uploadedStatementCsv('a.csv'),
        uploadedStatementCsv('b.csv'),
        uploadedStatementCsv('c.csv', "Date,Amount\n2026-01-01,1.00\n"),
    ]);

    expect($result->created)->toHaveCount(2)
        ->and($result->duplicates)->toBe(1);
});

it('stores valid files and reports per-file errors when one file is rejected', function () {
    $result = $this->service->upload($this->user, [
        uploadedStatementPdf('good.pdf'),
        uploadedStatementCsv('fake.pdf'),
        UploadedFile::fake()->createWithContent('empty.csv', ''),
    ]);

    expect($result->created)->toHaveCount(1)
        ->and($result->created[0]->original_filename)->toBe('good.pdf')
        ->and($result->errors)->toHaveCount(2)
        ->and($result->errorMessage())->toContain('fake.pdf')->toContain('empty.csv')
        ->and(Statement::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles('statements'))->toHaveCount(1);
});

it('rejects files whose extension the vault does not accept', function () {
    $result = $this->service->upload($this->user, [UploadedFile::fake()->createWithContent('notes.txt', 'hello')]);

    expect($result->created)->toBe([])
        ->and($result->errorMessage())->toContain('notes.txt');
});

it('rejects a pdf without a pdf header and a spreadsheet that is not a workbook', function () {
    $result = $this->service->upload($this->user, [
        UploadedFile::fake()->createWithContent('a.pdf', "\x00\x01binary data"),
        UploadedFile::fake()->createWithContent('b.xlsx', "PK\x03\x04 not really a workbook"),
        UploadedFile::fake()->createWithContent('c.csv', "%PDF-1.4\nbody"),
    ]);

    expect($result->created)->toBe([])
        ->and($result->errors)->toHaveCount(3);
});

it('accepts a real workbook and reads only its filename', function () {
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('xl/workbook.xml', '<workbook/>');
    $zip->close();

    $result = $this->service->upload($this->user, [UploadedFile::fake()->createWithContent('westpac_2026-02.xlsx', file_get_contents($path))]);
    unlink($path);

    expect($result->errors)->toBe([])
        ->and($result->created)->toHaveCount(1)
        ->and($result->created[0]->institution_hint)->toBe('westpac')
        ->and($result->created[0]->period_start?->toDateString())->toBe('2026-02-01');
});

it('rejects a file over 25 megabytes without storing it', function () {
    $result = $this->service->upload($this->user, [UploadedFile::fake()->createWithContent('big.pdf', '%PDF-1.4'.str_repeat('0', StatementUploadService::MAX_BYTES))]);

    expect($result->created)->toBe([])
        ->and($result->errorMessage())->toContain('25 MB')
        ->and(Storage::disk('local')->allFiles('statements'))->toBe([]);
});

it('suggests the single matching account without linking it', function () {
    $account = Account::factory()->for($this->user)->create(['institution' => 'CommBank', 'name' => 'Everyday']);
    Account::factory()->for($this->user)->create(['institution' => 'Westpac', 'name' => 'Savings']);

    $result = $this->service->upload($this->user, [uploadedStatementPdf('commbank_statement_2026-03.pdf')]);
    $statement = $result->created[0];

    expect($statement->suggested_account_id)->toBe($account->id)
        ->and((float) $statement->suggestion_confidence)->toBeGreaterThanOrEqual(0.35)
        ->and($statement->account_id)->toBeNull()
        ->and($statement->review_status)->toBe(StatementReviewStatus::Unmatched);
});

it('links the statement when a target account is given', function () {
    $account = Account::factory()->for($this->user)->create();

    $statement = $this->service->upload($this->user, [uploadedStatementCsv('march.csv')], $account)->created[0];

    expect($statement->account_id)->toBe($account->id)
        ->and($statement->review_status)->toBe(StatementReviewStatus::Linked)
        ->and($statement->suggested_account_id)->toBeNull();
});

it('never suggests or links another user\'s account', function () {
    $other = User::factory()->create();
    $foreign = Account::factory()->for($other)->create(['institution' => 'CommBank', 'name' => 'Everyday', 'account_last4' => '4321']);

    $statement = $this->service->upload($this->user, [uploadedStatementPdf('commbank_statement_xxxx4321_2026-03.pdf')])->created[0];

    expect($statement->suggested_account_id)->toBeNull()
        ->and(fn () => $this->service->upload($this->user, [uploadedStatementCsv('x.csv', "Date,Amount\n2026-02-01,1.00\n")], $foreign))
        ->toThrow(ValidationException::class)
        ->and(Statement::query()->count())->toBe(1);
});

it('reads the statement period from the csv date column', function () {
    $content = "Date,Description,Amount\n2026-03-03,Coffee,-4.50\n2026-03-15,Rent,-900.00\n2026-03-28,Salary,2000.00\n";

    $statement = $this->service->upload($this->user, [uploadedStatementCsv('export.csv', $content)])->created[0];

    expect($statement->period_start?->toDateString())->toBe('2026-03-03')
        ->and($statement->period_end?->toDateString())->toBe('2026-03-28');
});

it('creates no imports or transactions on upload', function () {
    $result = $this->service->upload($this->user, [uploadedStatementCsv('a.csv'), uploadedStatementPdf('b.pdf')], Account::factory()->for($this->user)->create());

    expect($result->errors)->toBe([])
        ->and($result->created)->toHaveCount(2)
        ->and(BankImport::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0);
});

it('records the content hash, size, type and stored file', function () {
    $statement = $this->service->upload($this->user, [uploadedStatementCsv('march.csv')])->created[0];

    expect($statement->content_hash)->toBe(hash('sha256', Storage::disk('local')->get($statement->stored_path)))
        ->and($statement->byte_size)->toBe(mb_strlen(Storage::disk('local')->get($statement->stored_path)))
        ->and($statement->file_type->value)->toBe('csv')
        ->and($statement->mime_type)->toBe('text/csv')
        ->and($statement->user_id)->toBe($this->user->id);
});

it('allows another user to upload the same file', function () {
    $this->service->upload($this->user, [uploadedStatementCsv('march.csv')]);
    $result = $this->service->upload(User::factory()->create(), [uploadedStatementCsv('march.csv')]);

    expect($result->created)->toHaveCount(1)
        ->and($result->duplicates)->toBe(0);
});

it('records the byte size of a file with multibyte characters', function () {
    $content = "Date,Description,Amount\n2026-03-03,Café Müller,-4.50\n";

    $statement = $this->service->upload($this->user, [uploadedStatementCsv('cafe.csv', $content)])->created[0];

    expect($statement->byte_size)->toBe(mb_strlen($content, '8bit'))
        ->and($statement->content_hash)->toBe(hash('sha256', $content));
});

it('hides database errors from the per-file message, reports them and removes the stored file', function () {
    Exceptions::fake();
    Statement::creating(fn () => throw new QueryException('mariadb', 'insert into statements', [], new Exception('Host: secret.internal')));

    $result = $this->service->upload($this->user, [uploadedStatementCsv('march.csv')]);

    expect($result->created)->toBe([])
        ->and($result->errorMessage())->toContain('march.csv')->not->toContain('secret')->not->toContain('insert')
        ->and(Storage::disk('local')->allFiles('statements'))->toBe([]);

    Exceptions::assertReported(QueryException::class);
});
