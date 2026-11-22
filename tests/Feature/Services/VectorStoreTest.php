<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Exceptions\VectorStoreException;
use App\Jobs\IndexDocumentJob;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentSearchService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    config()->set('budget.documents.vector_store', 'openai');
    config()->set('services.vector_store.openai.api_key', 'sk-secret-key');
    $this->user = User::factory()->create();
});

function vectorDocument(User $user, string $name = 'notes.txt', string $content = 'hello notes'): Document
{
    Storage::disk('local')->put('documents/'.$name, $content);

    return Document::factory()->for($user)->create(['filename' => $name, 'stored_path' => 'documents/'.$name]);
}

function vectorFake(int $addStatus = 200): void
{
    Http::fake([
        '*/vector_stores/*/files' => Http::response(['id' => 'vsf_1'], $addStatus),
        '*/vector_stores/*/search' => Http::response(['data' => [['filename' => 'Statement.pdf', 'score' => 0.91, 'content' => [['type' => 'text', 'text' => 'Rent paid 1 March']]]]]),
        '*/vector_stores' => Http::response(['id' => 'vs_user']),
        '*/files' => Http::response(['id' => 'file_1']),
    ]);
}

function runIndex(Document $document): void
{
    app()->call([new IndexDocumentJob($document), 'handle']);
}

it('indexes a document and records failures', function () {
    vectorFake();
    $document = vectorDocument($this->user);

    runIndex($document);

    expect($document->fresh()->status)->toBe(DocumentStatus::Ready)
        ->and($document->fresh()->vector_file_id)->toBe('file_1')
        ->and($this->user->fresh()->vector_store_id)->toBe('vs_user');

    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response('', 401)]);
    $refused = vectorDocument($this->user, 'two.txt');
    runIndex($refused);

    expect($refused->fresh()->status)->toBe(DocumentStatus::Error)
        ->and($refused->fresh()->error_message)->not->toContain('sk-secret-key');

    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response('', 500)]);
    $transient = vectorDocument($this->user, 'three.txt');

    expect(fn () => runIndex($transient))->toThrow(VectorStoreException::class);
    (new IndexDocumentJob($transient))->failed(new VectorStoreException('x', permanent: false));
    expect($transient->fresh()->status)->toBe(DocumentStatus::Error);
});

it('creates one store per user and reuses it', function () {
    vectorFake();

    runIndex(vectorDocument($this->user, 'a.txt'));
    runIndex(vectorDocument($this->user, 'b.txt'));

    expect(collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->url() === 'https://api.openai.com/v1/vector_stores')->count())->toBe(1);
});

it('marks a document whose content is not its type as an error without sending it', function () {
    vectorFake();
    $document = vectorDocument($this->user, 'evil.docx', "MZ\x90\x00 executable");

    runIndex($document);

    expect($document->fresh()->status)->toBe(DocumentStatus::Error)
        ->and($document->fresh()->error_message)->toBe('The file content does not match its type.');
    Http::assertNothingSent();
});

it('returns excerpts with filename and score', function () {
    vectorFake();
    runIndex(vectorDocument($this->user));

    $result = app(DocumentSearchService::class)->search($this->user->fresh(), 'rent');

    expect($result->excerpts)->toHaveCount(1)
        ->and($result->excerpts[0]->filename)->toBe('Statement.pdf')
        ->and($result->excerpts[0]->score)->toBe(0.91)
        ->and($result->excerpts[0]->excerpt)->toContain('Rent paid');
});

it('never indexes vault-only statements', function () {
    vectorFake();
    App\Models\Statement::factory()->for($this->user)->create();

    $result = app(DocumentSearchService::class)->search($this->user, 'anything');

    expect($result->excerpts)->toBe([])->and($result->message)->toBe('No documents have been uploaded to the document store yet');
    Http::assertNothingSent();
});

it('clamps the search limit to twenty', function () {
    vectorFake();
    runIndex(vectorDocument($this->user));

    app(DocumentSearchService::class)->search($this->user->fresh(), 'rent', 50);
    app(DocumentSearchService::class)->search($this->user->fresh(), 'rent', 0);

    $limits = collect(Http::recorded())->map(fn (array $pair): Request => $pair[0])->filter(fn (Request $r): bool => str_ends_with($r->url(), '/search'))->map(fn (Request $r): int => $r['max_num_results'])->values()->all();

    expect($limits)->toBe([20, 1]);
});

it('reports when no documents have been uploaded', function () {
    vectorFake();

    expect(app(DocumentSearchService::class)->search($this->user, 'rent')->message)->toBe('No documents have been uploaded to the document store yet');
});

it('reports when nothing matches', function () {
    Http::fake(['*/search' => Http::response(['data' => []])]);
    $this->user->forceFill(['vector_store_id' => 'vs_user'])->save();
    vectorDocument($this->user);

    expect(app(DocumentSearchService::class)->search($this->user->fresh(), 'zzz')->message)->toBe('No matching documents found');
});

it('searches only the user\'s own document store', function () {
    vectorFake();
    $other = User::factory()->create(['vector_store_id' => 'vs_other']);
    vectorDocument($other);
    runIndex(vectorDocument($this->user));

    app(DocumentSearchService::class)->search($this->user->fresh(), 'rent');

    $urls = collect(Http::recorded())->map(fn (array $pair): string => $pair[0]->url())->filter(fn (string $url): bool => str_ends_with($url, '/search'))->values()->all();

    expect($urls)->toBe(['https://api.openai.com/v1/vector_stores/vs_user/search']);
});

it('does not expose the vector store id when a user is serialised', function () {
    $user = User::factory()->create(['vector_store_id' => 'vs_secret']);

    expect($user->toArray())->not->toHaveKey('vector_store_id');
});

it('only treats the store as configured when the driver and the key are both set', function () {
    config()->set('services.vector_store.openai.api_key', '');
    expect(App\Support\Documents\VectorStoreStatus::configured())->toBeFalse();

    config()->set('services.vector_store.openai.api_key', 'k');
    config()->set('budget.documents.vector_store', null);
    expect(App\Support\Documents\VectorStoreStatus::configured())->toBeFalse();
});

it('accepts a real pdf with binary streams and refuses a pdf that is not one', function () {
    $sniffer = app(App\Services\Documents\DocumentContentSniffer::class);
    Storage::disk('local')->put('p.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nstream\n\x00\x01\x02\nendstream");
    Storage::disk('local')->put('q.pdf', 'just text');

    expect($sniffer->matches('PDF', Storage::disk('local')->path('p.pdf')))->toBeTrue()
        ->and($sniffer->matches('pdf', Storage::disk('local')->path('q.pdf')))->toBeFalse();
});

it('deletes the uploaded file when adding it to the store fails', function () {
    Http::fake([
        '*/vector_stores/*/files' => Http::response('', 500),
        '*/vector_stores' => Http::response(['id' => 'vs_user']),
        'https://api.openai.com/v1/files/file_1' => Http::response(['deleted' => true]),
        '*/files' => Http::response(['id' => 'file_1']),
    ]);

    expect(fn () => runIndex(vectorDocument($this->user)))->toThrow(VectorStoreException::class);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/files/file_1'));
});
