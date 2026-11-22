<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PdfImportStatus;
use App\Enums\StatementDocumentType;
use App\Enums\TransactionDirection;
use App\Jobs\ExtractStatementPdfJob;
use App\Models\PdfImport;
use App\Models\Statement;
use App\Notifications\PdfImportReadyNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    Sleep::fake();
    config([
        'budget.pdf_import.enabled' => true,
        'services.statement_ai.api_key' => 'sk-test-secret',
        'services.statement_ai.base_url' => 'https://ai.example.test/v1',
    ]);

    Storage::disk('local')->put('statements/s.pdf', '%PDF-1.4 fake');
    $this->statement = Statement::factory()->create(['stored_path' => 'statements/s.pdf']);
    $this->import = PdfImport::factory()->create(['user_id' => $this->statement->user_id, 'statement_id' => $this->statement->id]);
});

function aiReply(array $payload, int $status = 200)
{
    return Http::response(['content' => [['type' => 'text', 'text' => json_encode($payload)]]], $status);
}

function aiLine(string $date, string $amount, string $direction = 'debit', string $description = 'Coffee'): array
{
    return ['raw_date' => $date, 'amount' => $amount, 'direction' => $direction, 'description' => $description, 'kind' => 'statement_only'];
}

function runExtraction(PdfImport $import): PdfImport
{
    dispatch_sync(new ExtractStatementPdfJob($import));

    return $import->fresh();
}

it('fails the import with the provider message when no AI provider is configured', function () {
    config(['services.statement_ai.api_key' => null]);
    Http::fake();

    $import = runExtraction($this->import);

    expect($import->status)->toBe(PdfImportStatus::Failed)
        ->and($import->failure_message)->toBe('AI provider not configured')
        ->and($import->lines()->count())->toBe(0);

    Http::assertNothingSent();
    Notification::assertNothingSent();
});

it('stores extracted lines for a bank statement', function () {
    Http::fake(['ai.example.test/*' => aiReply([
        'document_type' => 'bank_statement',
        'date_format' => 'd/m/Y',
        'lines' => [
            aiLine('01/03/2026', '4.50'),
            aiLine('02/03/2026', '1234.50', 'credit', 'Salary'),
            aiLine('03/03/2026', '0.99'),
            aiLine('04/03/2026', '20'),
            aiLine('05/03/2026', '100.10', 'credit', 'Refund'),
        ],
    ])]);

    $import = runExtraction($this->import);
    $lines = $import->lines()->orderBy('id')->get();

    expect($import->status)->toBe(PdfImportStatus::Ready)
        ->and($import->document_type)->toBe(StatementDocumentType::BankStatement)
        ->and($import->completed_at)->not->toBeNull()
        ->and($lines)->toHaveCount(5)
        ->and($lines->pluck('amount')->all())->toBe([-450, 123450, -99, -2000, 10010])
        ->and($lines[1]->direction)->toBe(TransactionDirection::Credit)
        ->and($lines[0]->line_date?->toDateString())->toBe('2026-03-01')
        ->and($import->summary['lines_found'])->toBe(5);

    Notification::assertSentTo($import->user, PdfImportReadyNotification::class);
});

it('sends the pdf as a base64 document block with the provider headers', function () {
    Http::fake(['ai.example.test/*' => aiReply(['document_type' => 'other', 'date_format' => null, 'lines' => []])]);

    runExtraction($this->import);

    Http::assertSent(function (Request $request): bool {
        $content = $request['messages'][0]['content'];

        return $request->url() === 'https://ai.example.test/v1/messages'
            && $request->header('x-api-key') === ['sk-test-secret']
            && $request->header('anthropic-version') === ['2023-06-01']
            && $request['model'] === 'claude-sonnet-4-5'
            && $content[0]['type'] === 'document'
            && $content[0]['source'] === ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode('%PDF-1.4 fake')];
    });
});

it('completes a non-statement document with no lines', function () {
    Http::fake(['ai.example.test/*' => aiReply([
        'document_type' => 'contract',
        'date_format' => null,
        'lines' => [aiLine('01/03/2026', '4.50')],
    ])]);

    $import = runExtraction($this->import);

    expect($import->status)->toBe(PdfImportStatus::Ready)
        ->and($import->document_type)->toBe(StatementDocumentType::Contract)
        ->and($import->lines()->count())->toBe(0);
});

it('keeps lines with unreadable dates and flags them for review', function () {
    Http::fake(['ai.example.test/*' => aiReply([
        'document_type' => 'credit_card_statement',
        'date_format' => 'd/m/Y',
        'lines' => [aiLine('01/03/2026', '4.50'), aiLine('31/02/2026', '9.00'), aiLine('sometime', '1.00')],
    ])]);

    $import = runExtraction($this->import);
    $lines = $import->lines()->orderBy('id')->get();

    expect($lines)->toHaveCount(3)
        ->and($lines->pluck('date_unreadable')->all())->toBe([false, true, true])
        ->and($lines[1]->line_date)->toBeNull()
        ->and($lines[1]->raw_date)->toBe('31/02/2026')
        ->and($import->summary['dates_unreadable'])->toBe(2);
});

it('prefers the date format chosen for the import over the detected one', function () {
    Http::fake(['ai.example.test/*' => aiReply([
        'document_type' => 'bank_statement',
        'date_format' => 'd/m/Y',
        'lines' => [aiLine('03/04/2026', '4.50')],
    ])]);
    $this->import->update(['date_format' => 'm/d/Y']);

    $line = runExtraction($this->import)->lines()->sole();

    expect($line->line_date?->toDateString())->toBe('2026-03-04');
});

it('fails with the unsupported message when the provider rejects pdfs', function () {
    Http::fake(['ai.example.test/*' => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'PDF document type is not supported by this model']], 400)]);

    $import = runExtraction($this->import);

    expect($import->status)->toBe(PdfImportStatus::Failed)
        ->and($import->failure_message)->toBe('AI provider does not support PDF processing');
    Http::assertSentCount(1);
});

it('fails permanently with a generic message on other client errors', function (int $status) {
    Http::fake(['ai.example.test/*' => Http::response(['error' => ['message' => 'sk-test-secret is invalid, body details']], $status)]);
    Log::spy();

    $import = runExtraction($this->import);

    expect($import->status)->toBe(PdfImportStatus::Failed)
        ->and($import->failure_message)->not->toContain('sk-test-secret')->not->toContain('body details');
    Http::assertSentCount(1);
})->with([400, 401, 403]);

it('retries a server error once with backoff and then succeeds', function () {
    Http::fake(['ai.example.test/*' => Http::sequence()
        ->push('', 529)
        ->push(['content' => [['type' => 'text', 'text' => json_encode(['document_type' => 'other', 'date_format' => null, 'lines' => []])]]])]);

    $import = runExtraction($this->import);

    expect($import->status)->toBe(PdfImportStatus::Ready);
    Http::assertSentCount(2);
    Sleep::assertSleptTimes(1);
});

it('gives up after the retries and fails with an unavailable message', function () {
    Http::fake(['ai.example.test/*' => Http::response('', 503)]);

    $import = runExtraction($this->import);

    expect($import->status)->toBe(PdfImportStatus::Failed)
        ->and($import->failure_message)->toBe('The AI provider is unavailable. Please try again later.');
    Http::assertSentCount(2);
});

it('fails cleanly on a result that is not the expected json', function (mixed $text) {
    Http::fake(['ai.example.test/*' => Http::response(['content' => [['type' => 'text', 'text' => $text]]])]);

    $import = runExtraction($this->import);

    expect($import->status)->toBe(PdfImportStatus::Failed)
        ->and($import->failure_message)->toBe('The AI provider returned an unreadable result.')
        ->and($import->lines()->count())->toBe(0);
})->with([
    'not json' => ['Here are your transactions: none'],
    'wrong type' => [json_encode(['document_type' => 'poem', 'date_format' => null, 'lines' => []])],
    'amount as float' => [json_encode(['document_type' => 'bank_statement', 'date_format' => null, 'lines' => [['raw_date' => '1', 'amount' => 12.5, 'direction' => 'debit', 'description' => 'x']]])],
    'amount with symbol' => [json_encode(['document_type' => 'bank_statement', 'date_format' => null, 'lines' => [['raw_date' => '1', 'amount' => '$12.50', 'direction' => 'debit', 'description' => 'x']]])],
    'bad direction' => [json_encode(['document_type' => 'bank_statement', 'date_format' => null, 'lines' => [['raw_date' => '1', 'amount' => '1.00', 'direction' => 'sideways', 'description' => 'x']]])],
    'unknown date format' => [json_encode(['document_type' => 'bank_statement', 'date_format' => 'yyyy', 'lines' => []])],
]);

it('fails with a useful message when the reply was cut off at the token limit', function () {
    Http::fake(['ai.example.test/*' => Http::response(['stop_reason' => 'max_tokens', 'content' => [['type' => 'text', 'text' => '{"document_type": "bank_statem']]])]);

    $import = runExtraction($this->import);

    expect($import->status)->toBe(PdfImportStatus::Failed)
        ->and($import->failure_message)->toContain('too many lines');
});

it('rejects an amount with a trailing newline', function () {
    Http::fake(['ai.example.test/*' => aiReply(['document_type' => 'bank_statement', 'date_format' => null, 'lines' => [aiLine('01/03/2026', "12.34\n")]])]);

    expect(runExtraction($this->import)->failure_message)->toBe('The AI provider returned an unreadable result.');
});

it('does not overwrite an import that failed while the provider was still answering', function () {
    Http::fake(['ai.example.test/*' => function () {
        PdfImport::query()->whereKey($this->import->id)->update(['status' => PdfImportStatus::Failed, 'failure_message' => 'The statement could not be processed.']);

        return aiReply(['document_type' => 'bank_statement', 'date_format' => 'd/m/Y', 'lines' => [aiLine('01/03/2026', '4.50')]]);
    }]);

    $import = runExtraction($this->import);

    expect($import->status)->toBe(PdfImportStatus::Failed)
        ->and($import->lines()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('runs on the long statements queue so retry_after outlasts the job timeout', function () {
    $job = new ExtractStatementPdfJob($this->import);

    expect($job->connection)->toBe('redis_long')
        ->and($job->queue)->toBe('statements')
        ->and(config('queue.connections.redis_long.retry_after'))->toBeGreaterThan($job->timeout)
        ->and(config('horizon.defaults.supervisor-statements.timeout'))->toBeGreaterThan($job->timeout);
});

it('accepts the json inside a code fence', function () {
    $json = json_encode(['document_type' => 'bank_statement', 'date_format' => 'Y-m-d', 'lines' => [aiLine('2026-03-01', '4.50')]]);
    Http::fake(['ai.example.test/*' => Http::response(['content' => [['type' => 'text', 'text' => "```json\n{$json}\n```"]]])]);

    expect(runExtraction($this->import)->lines()->count())->toBe(1);
});

it('does nothing for an import that is not pending', function () {
    Http::fake();
    $this->import->update(['status' => PdfImportStatus::Ready]);

    runExtraction($this->import);

    Http::assertNothingSent();
});

it('fails when the feature is switched off, the file is gone or too large', function (string $case) {
    Http::fake();

    match ($case) {
        'off' => config(['budget.pdf_import.enabled' => false]),
        'gone' => Storage::disk('local')->delete('statements/s.pdf'),
        'large' => config(['services.statement_ai.max_pdf_bytes' => 5]),
    };

    $import = runExtraction($this->import);

    expect($import->status)->toBe(PdfImportStatus::Failed)->and($import->failure_message)->not->toBeEmpty();
    Http::assertNothingSent();
})->with(['off', 'gone', 'large']);

it('marks an unexpected failure as failed without exposing details', function () {
    Log::spy();
    $job = new ExtractStatementPdfJob($this->import);

    $job->failed(new RuntimeException('provider said sk-test-secret'));

    expect($this->import->fresh()->status)->toBe(PdfImportStatus::Failed)
        ->and($this->import->fresh()->failure_message)->not->toContain('sk-test-secret');

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $context['exception'] === RuntimeException::class && ! str_contains(json_encode($context), 'sk-test-secret'));
});

it('adds the analysed pdf to the document store only when one is configured', function () {
    Illuminate\Support\Facades\Queue::fake([App\Jobs\IndexDocumentJob::class]);
    Http::fake(['*' => aiReply(['document_type' => 'bank_statement', 'date_format' => 'd/m/Y', 'lines' => [aiLine('01/03/2026', '4.50')]])]);

    runExtraction($this->import);
    Illuminate\Support\Facades\Queue::assertNotPushed(App\Jobs\IndexDocumentJob::class);

    config(['budget.documents.vector_store' => 'openai', 'services.vector_store.openai.api_key' => 'sk-vs']);
    $second = PdfImport::factory()->create(['user_id' => $this->statement->user_id, 'statement_id' => $this->statement->id]);
    runExtraction($second);

    Illuminate\Support\Facades\Queue::assertPushed(App\Jobs\IndexDocumentJob::class, 1);

    $third = PdfImport::factory()->create(['user_id' => $this->statement->user_id, 'statement_id' => $this->statement->id]);
    runExtraction($third);
    Illuminate\Support\Facades\Queue::assertPushed(App\Jobs\IndexDocumentJob::class, 1);
    expect(App\Models\Document::query()->where('user_id', $this->statement->user_id)->sole()->stored_path)->toBe('statements/s.pdf');
});

it('keeps the import ready when the document store cannot be queued', function () {
    config(['budget.documents.vector_store' => 'openai', 'services.vector_store.openai.api_key' => 'sk-vs']);
    Http::fake(['*' => aiReply(['document_type' => 'bank_statement', 'date_format' => 'd/m/Y', 'lines' => [aiLine('01/03/2026', '4.50')]])]);
    App\Models\Document::creating(fn () => throw new RuntimeException('down'));

    expect(runExtraction($this->import)->status)->toBe(PdfImportStatus::Ready);
});
