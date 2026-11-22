<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Livewire\ImportBank;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
});

function documentUpload(User $user, UploadedFile $file)
{
    return Livewire::actingAs($user)->test(ImportBank::class)->set('documentFile', $file)->call('importDocument');
}

it('refuses document uploads when no vector store is configured', function () {
    config()->set('budget.documents.vector_store', null);

    documentUpload($this->user, UploadedFile::fake()->create('notes.docx', 10))->assertSet('errorMessage', 'No vector store is configured');

    expect(Document::query()->count())->toBe(0);
});

it('refuses file types the active vector store does not accept', function () {
    config()->set('budget.documents.vector_store', 'openai');

    documentUpload($this->user, UploadedFile::fake()->create('virus.exe', 10))->assertSet('errorMessage', 'Invalid document file type for the active vector store');

    expect(Document::query()->count())->toBe(0);
});

it('refuses documents over 25 MB', function () {
    config()->set('budget.documents.vector_store', 'openai');

    Livewire::actingAs($this->user)->test(ImportBank::class)->set('documentFile', UploadedFile::fake()->create('big.xlsx', 26 * 1024))->assertHasErrors('documentFile');

    expect(fn () => app(App\Services\DocumentUploadService::class)->store($this->user, UploadedFile::fake()->create('big.xlsx', 26 * 1024)))
        ->toThrow(App\Exceptions\DocumentRefused::class, 'at most 25 MB')
        ->and(Document::query()->count())->toBe(0);
});

it('stores an uploaded document as pending and records an audit event', function () {
    config()->set('budget.documents.vector_store', 'openai');

    documentUpload($this->user, UploadedFile::fake()->create('notes.docx', 10))->assertSet('documentMessage', 'Document stored.');

    $document = Document::query()->sole();

    expect($document->user_id)->toBe($this->user->id)
        ->and($document->status)->toBe(DocumentStatus::Pending)
        ->and($document->content_hash)->toHaveLength(64)
        ->and(Storage::disk('local')->exists($document->stored_path))->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'livewire.import-bank.importDocument')->where('user_id', $this->user->id)->exists())->toBeTrue();
});

it('scopes documents to their owner', function () {
    $mine = Document::factory()->for($this->user)->create();
    Document::factory()->create();

    expect(Document::query()->forUser($this->user)->pluck('id')->all())->toBe([$mine->id]);
});

it('hides the document option while no vector store is configured', function () {
    config()->set('budget.documents.vector_store', null);
    Livewire::actingAs($this->user)->test(ImportBank::class)->assertDontSeeHtml('import-bank-document-input');

    config()->set('budget.documents.vector_store', 'openai');
    Livewire::actingAs($this->user)->test(ImportBank::class)->assertSeeHtml('import-bank-document-input');
});

it('records the real mime type of the stored file', function () {
    config()->set('budget.documents.vector_store', 'openai');

    documentUpload($this->user, UploadedFile::fake()->createWithContent('notes.txt', 'plain text notes'));

    expect(Document::query()->sole()->mime_type)->toBe('text/plain');
});

it('removes the stored file when the record cannot be saved', function () {
    config()->set('budget.documents.vector_store', 'openai');
    Document::creating(fn () => throw new RuntimeException('boom'));

    expect(fn () => app(App\Services\DocumentUploadService::class)->store($this->user, UploadedFile::fake()->create('notes.docx', 10)))->toThrow(RuntimeException::class)
        ->and(Storage::disk('local')->allFiles('documents'))->toBe([]);
});

it('tells the user when a pdf cannot be routed because pdf import is off', function () {
    config()->set('budget.documents.vector_store', 'openai');
    config()->set('budget.pdf_import.enabled', false);

    documentUpload($this->user, UploadedFile::fake()->create('statement.PDF', 10))->assertSet('errorMessage', 'PDF import is not enabled.');
});
