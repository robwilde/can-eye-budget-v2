<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BankImportStatus;
use App\Enums\ImportPreset;
use App\Jobs\ImportCsvTransactionsJob;
use App\Livewire\ImportBank;
use App\Models\Account;
use App\Models\BankImport;
use App\Models\User;
use App\Services\CsvImport\CsvImporter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CallsApi;

uses(CallsApi::class);

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('local');
    $this->user = User::factory()->create();
});

function parityCsv(string $content, string $name = 'statement.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

function parityApiPost(string $token, array $data): Illuminate\Testing\TestResponse
{
    app('auth')->forgetGuards();

    return test()->withToken($token)->post('/api/v1/imports', $data, ['Accept' => 'application/json']);
}

const PARITY_BANK_CSV = "Date,Description,Amount\n10/05/2026,Coffee,-4.50\n11/05/2026,Salary,2500.00\n";
const PARITY_YNAB_CSV = "Date,Payee,Category,Outflow,Inflow\n05/10/2026,Coffee,Food,4.50,\n05/11/2026,Salary,Income,,2500.00\n";
const PARITY_ONE_BAD_ROW = "Date,Description,Amount\n10/05/2026,Coffee,-4.50\n11/05/2026,Rent,abc\n12/05/2026,Salary,2500.00\n";

it('applies the strict CSV rules only when the API option is set', function () {
    $account = Account::factory()->for($this->user)->create()->refresh();
    $importer = app(CsvImporter::class);

    expect(fn () => $importer->import($this->user, $account, parityCsv(PARITY_ONE_BAD_ROW), ImportPreset::BankCsv))->toThrow(ValidationException::class)
        ->and(BankImport::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Queue::assertNothingPushed();

    parityApiPost($this->apiTokenFor($this->user), ['file' => parityCsv(PARITY_ONE_BAD_ROW), 'account_id' => $account->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    expect(BankImport::query()->count())->toBe(0);

    $lenient = $importer->import($this->user, $account, parityCsv(PARITY_ONE_BAD_ROW), ImportPreset::BankCsv, strict: false);

    expect($lenient->status)->toBe(BankImportStatus::Pending)
        ->and($lenient->rows()->count())->toBe(3)
        ->and($lenient->rows()->invalid()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1)
        ->and($account->fresh()->column_mapping)->toBeNull();
    Queue::assertPushed(ImportCsvTransactionsJob::class, 1);

    expect(fn () => $importer->import($this->user, $account, parityCsv("foo,bar,baz\n1,2,3\n"), ImportPreset::BankCsv, strict: false))->toThrow(ValidationException::class)
        ->and(BankImport::query()->count())->toBe(1);

    $clean = $importer->import($this->user, $account, parityCsv(PARITY_BANK_CSV), ImportPreset::BankCsv, strict: false);

    expect($clean->rows()->invalid()->count())->toBe(0)
        ->and($account->fresh()->column_mapping)->toBe($clean->column_mapping);
});

it('detects the same preset and formats from the import screen and the API', function (ImportPreset $preset, string $content) {
    $viaApi = Account::factory()->for($this->user)->create();
    $viaScreen = Account::factory()->for($this->user)->create();

    parityApiPost($this->apiTokenFor($this->user), ['file' => parityCsv($content), 'account_id' => $viaApi->id, 'preset' => $preset->value])->assertAccepted();

    Livewire::actingAs($this->user)->test(ImportBank::class)
        ->set('accountChoice', 'existing')
        ->set('accountId', $viaScreen->id)
        ->set('importPreset', $preset->value)
        ->set('file', parityCsv($content))
        ->call('uploadAndDetectHeaders')
        ->assertSet('step', 2);

    $api = BankImport::query()->where('account_id', $viaApi->id)->sole();
    $screen = BankImport::query()->where('account_id', $viaScreen->id)->sole();

    expect($screen->preset)->toBe($preset)
        ->and($screen->column_mapping)->toBe($api->column_mapping)
        ->and($screen->column_mapping)->not->toBeEmpty();
})->with([
    'bank csv' => [ImportPreset::BankCsv, PARITY_BANK_CSV],
    'ynab export' => [ImportPreset::Ynab, PARITY_YNAB_CSV],
]);

it('refuses a bank-connected account from both import paths', function () {
    $connected = Account::factory()->for($this->user)->withRedbark()->create()->refresh();

    parityApiPost($this->apiTokenFor($this->user), ['file' => parityCsv(PARITY_BANK_CSV), 'account_id' => $connected->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account_id');

    expect(fn () => app(CsvImporter::class)->import($this->user, $connected, parityCsv(PARITY_BANK_CSV), ImportPreset::BankCsv))->toThrow(ValidationException::class);

    Livewire::actingAs($this->user)->test(ImportBank::class)
        ->set('accountChoice', 'existing')
        ->set('accountId', $connected->id)
        ->set('file', parityCsv(PARITY_BANK_CSV))
        ->call('uploadAndDetectHeaders')
        ->assertSet('step', 1)
        ->assertSet('errorMessage', fn ($message) => str_contains((string) $message, 'connected via your bank'));

    expect(BankImport::query()->count())->toBe(0);
});
