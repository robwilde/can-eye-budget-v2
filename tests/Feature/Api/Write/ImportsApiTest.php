<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ApiKeyScope;
use App\Enums\BankImportStatus;
use App\Enums\ImportPreset;
use App\Jobs\ImportCsvTransactionsJob;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CallsApi;

uses(CallsApi::class);

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('local');
});

function importsApiCsv(string $name = 'statement.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, implode("\n", [
        'Date,Description,Amount',
        '10/05/2026,Coffee,-4.50',
        '11/05/2026,Salary,2500.00',
        '',
    ]));
}

/**
 * @param  array<string, mixed>  $data
 */
function importsApiPost(string $token, array $data): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withToken($token)->post('/api/v1/imports', $data, ['Accept' => 'application/json']);
}

it('queues a csv import for the token owner and returns 202', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $response = importsApiPost($this->apiTokenFor($user), ['file' => importsApiCsv(), 'account_id' => $account->id])
        ->assertAccepted()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.account_id', $account->id)
        ->assertJsonPath('data.preset', 'bank_csv')
        ->assertJsonMissingPath('data.stored_path');

    expect($response->json('data.imported_count'))->toBeInt()->and($response->json('data.skipped_count'))->toBeInt();

    $import = BankImport::query()->sole();

    expect($response->json('data.id'))->toBe($import->id)
        ->and($import->user_id)->toBe($user->id)
        ->and($import->account_id)->toBe($account->id)
        ->and($import->status)->toBe(BankImportStatus::Pending)
        ->and($import->preset)->toBe(ImportPreset::BankCsv)
        ->and($import->original_filename)->toBe('statement.csv')
        ->and($import->stored_path)->toStartWith('bank-imports/'.$user->id.'/')
        ->and($import->stored_path)->not->toContain('statement')
        ->and($import->rows()->count())->toBe(2)
        ->and($import->column_mapping)->toMatchArray(['date' => 'Date', 'description' => 'Description', 'amount' => 'Amount', 'date_format' => 'd/m/Y']);

    Storage::disk('local')->assertExists($import->stored_path);
    Queue::assertPushed(ImportCsvTransactionsJob::class, fn (ImportCsvTransactionsJob $job): bool => $job->bankImport->is($import));
    Queue::assertPushed(ImportCsvTransactionsJob::class, 1);
});

it('uses the requested preset to read an export', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $file = UploadedFile::fake()->createWithContent('ynab.csv', implode("\n", [
        'Date,Payee,Category,Outflow,Inflow',
        '05/10/2026,Coffee,Food,4.50,',
        '05/11/2026,Salary,Income,,2500.00',
        '',
    ]));

    importsApiPost($this->apiTokenFor($user), ['file' => $file, 'account_id' => $account->id, 'preset' => 'ynab'])
        ->assertAccepted()
        ->assertJsonPath('data.preset', 'ynab');

    expect(BankImport::query()->sole()->rows()->count())->toBe(2);
    Queue::assertPushed(ImportCsvTransactionsJob::class, 1);
});

it('rejects an import into another user\'s account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for(User::factory()->create())->create();

    importsApiPost($this->apiTokenFor($user), ['file' => importsApiCsv(), 'account_id' => $account->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account_id');

    expect(BankImport::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
});

it('refuses an import with a read-only key as insufficient scope', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    importsApiPost($this->apiTokenFor($user, ApiKeyScope::Read), ['file' => importsApiCsv(), 'account_id' => $account->id])
        ->assertForbidden()
        ->assertExactJson(['message' => 'insufficient scope']);

    expect(BankImport::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
});

it('rejects a file that is not a csv', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    importsApiPost($this->apiTokenFor($user), ['file' => UploadedFile::fake()->create('statement.pdf', 20, 'application/pdf'), 'account_id' => $account->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    expect(BankImport::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
});

it('rejects an oversized csv', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    importsApiPost($this->apiTokenFor($user), ['file' => UploadedFile::fake()->create('big.csv', 10241, 'text/csv'), 'account_id' => $account->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    expect(BankImport::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
});

it('rejects a csv that cannot be parsed and stores nothing', function (string $content) {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    importsApiPost($this->apiTokenFor($user), [
        'file' => UploadedFile::fake()->createWithContent('broken.csv', $content),
        'account_id' => $account->id,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    expect(BankImport::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
})->with([
    'no recognisable columns' => ["foo,bar,baz\n1,2,3\n"],
    'headers only' => ["Date,Description,Amount\n"],
    'dates that match no format' => ["Date,Description,Amount\nsoon,Coffee,-4.50\n"],
    'empty file' => [''],
]);

it('rejects an unsupported preset', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    importsApiPost($this->apiTokenFor($user), ['file' => importsApiCsv(), 'account_id' => $account->id, 'preset' => 'qif'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('preset');

    Queue::assertNothingPushed();
});

it('shows the status of the token owner\'s import without its stored path', function () {
    $user = User::factory()->create();
    $import = BankImport::factory()->completed()->create(['user_id' => $user->id, 'account_id' => Account::factory()->for($user)]);

    $this->apiCall('GET', 'imports/'.$import->id, $this->apiTokenFor($user, ApiKeyScope::Read))
        ->assertOk()
        ->assertJsonPath('data.id', $import->id)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonMissingPath('data.stored_path')
        ->assertJsonStructure(['data' => ['id', 'status', 'row_count', 'imported_count', 'skipped_count', 'restored_count']]);
});

it('returns 404 for another user\'s import', function () {
    $user = User::factory()->create();
    $import = BankImport::factory()->create();

    $this->apiCall('GET', 'imports/'.$import->id, $this->apiTokenFor($user))->assertNotFound();
});

it('records an http audit event for an api import', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    importsApiPost($this->apiTokenFor($user), ['file' => importsApiCsv(), 'account_id' => $account->id])->assertAccepted();

    $event = AuditEvent::query()->where('action', 'http.api.v1.imports.store')->sole();

    expect($event->user_id)->toBe($user->id)->and($event->outcome->value)->toBe('success');
});
