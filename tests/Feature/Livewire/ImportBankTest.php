<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BankImportStatus;
use App\Enums\ImportSource;
use App\Jobs\ImportCsvTransactionsJob;
use App\Livewire\ImportBank;
use App\Models\Account;
use App\Models\BankImport;
use App\Models\User;
use App\Services\CsvImport\CsvColumnMapper;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
});

function fixtureUpload(string $name = 'StatementCsv-Westpac.csv'): UploadedFile
{
    $contents = file_get_contents(base_path('tests/Fixtures/'.$name));

    return UploadedFile::fake()->createWithContent($name, $contents);
}

test('step 1 starts at upload', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->assertSet('step', 1)
        ->assertSee('Upload a CSV statement');
});

test('uploading and selecting an existing csv account moves to step 2 with auto-suggested mapping', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->csvImport()->create();

    Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'existing')
        ->set('accountId', $account->id)
        ->set('file', fixtureUpload())
        ->call('uploadAndDetectHeaders')
        ->assertSet('step', 2)
        ->assertSet('mapping.'.CsvColumnMapper::FIELD_DATE, 'Entered Date')
        ->assertSet('mapping.'.CsvColumnMapper::FIELD_AMOUNT, 'Amount');
});

test('uploading to a basiq-connected account is rejected with an error message', function () {
    $user = User::factory()->create();
    $basiqAccount = Account::factory()->for($user)->withBasiq()->create();

    Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'existing')
        ->set('accountId', $basiqAccount->id)
        ->set('file', fixtureUpload())
        ->call('uploadAndDetectHeaders')
        ->assertSet('step', 1)
        ->assertSet('errorMessage', fn ($v) => str_contains((string) $v, 'connected via your bank'));
});

test('inline account creation registers a new csv account with last 4 digits', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'new')
        ->set('newAccountName', 'Westpac Choice')
        ->set('newAccountLast4', '4599')
        ->set('file', fixtureUpload())
        ->call('uploadAndDetectHeaders')
        ->assertSet('step', 2);

    $account = Account::query()->where('user_id', $user->id)->first();

    expect($account)->not->toBeNull()
        ->and($account->name)->toBe('Westpac Choice')
        ->and($account->account_last4)->toBe('4599')
        ->and($account->import_source)->toBe(ImportSource::Csv);
});

test('confirmImport dispatches the job and moves to step 3', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->csvImport()->create();

    Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'existing')
        ->set('accountId', $account->id)
        ->set('file', fixtureUpload())
        ->call('uploadAndDetectHeaders')
        ->call('confirmImport')
        ->assertSet('step', 3);

    Queue::assertPushed(ImportCsvTransactionsJob::class);
});

test('confirmImport persists mapping back to the account for next time', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->csvImport()->create([
        'column_mapping' => null,
    ]);

    Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'existing')
        ->set('accountId', $account->id)
        ->set('file', fixtureUpload())
        ->call('uploadAndDetectHeaders')
        ->call('confirmImport');

    $account->refresh();
    expect($account->column_mapping)->not->toBeNull()
        ->and($account->column_mapping[CsvColumnMapper::FIELD_DATE])->toBe('Entered Date');
});

test('confirmImport refuses if mapping is missing the date column', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->csvImport()->create();

    Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'existing')
        ->set('accountId', $account->id)
        ->set('file', fixtureUpload())
        ->call('uploadAndDetectHeaders')
        ->set('mapping.'.CsvColumnMapper::FIELD_DATE, '')
        ->call('confirmImport')
        ->assertHasErrors(['mapping.date']);

    Queue::assertNotPushed(ImportCsvTransactionsJob::class);
});

test('confirmImport refuses if account changes to a basiq account', function () {
    $user = User::factory()->create();
    $csvAccount = Account::factory()->for($user)->csvImport()->create();
    $basiqAccount = Account::factory()->for($user)->withBasiq()->create();

    Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'existing')
        ->set('accountId', $csvAccount->id)
        ->set('file', fixtureUpload())
        ->call('uploadAndDetectHeaders')
        ->set('accountId', $basiqAccount->id)
        ->call('confirmImport')
        ->assertHasErrors('account_id')
        ->assertSet('step', 2);

    Queue::assertNotPushed(ImportCsvTransactionsJob::class);
});

test('pollStatus emits csv-import-complete when terminal', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->csvImport()->create();
    $bankImport = BankImport::factory()->for($user)->for($account)->completed()->create();

    Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('bankImportId', $bankImport->id)
        ->set('step', 3)
        ->call('pollStatus')
        ->assertDispatched('csv-import-complete', status: BankImportStatus::Completed->value);
});

test('startOver resets the wizard back to step 1', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->csvImport()->create();

    Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'existing')
        ->set('accountId', $account->id)
        ->set('file', fixtureUpload())
        ->call('uploadAndDetectHeaders')
        ->call('startOver')
        ->assertSet('step', 1)
        ->assertSet('bankImportId', null);
});

test('confirmImport refuses to act on another users BankImport via tampered bankImportId', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $intruderAccount = Account::factory()->for($intruder)->csvImport()->create();
    $ownerAccount = Account::factory()->for($owner)->csvImport()->create();
    $foreignImport = BankImport::factory()
        ->for($owner)
        ->for($ownerAccount)
        ->create(['status' => BankImportStatus::Previewing]);

    expect(fn () => Livewire::actingAs($intruder)
        ->test(ImportBank::class)
        ->set('accountId', $intruderAccount->id)
        ->set('mapping', ['date' => 'Date', 'amount' => 'Amount'])
        ->set('bankImportId', $foreignImport->id)
        ->call('confirmImport'))
        ->toThrow(ModelNotFoundException::class);

    Queue::assertNotPushed(ImportCsvTransactionsJob::class);
    expect($foreignImport->fresh()->status)->toBe(BankImportStatus::Previewing);
});

test('pollStatus does not emit complete event for another users BankImport', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $ownerAccount = Account::factory()->for($owner)->csvImport()->create();
    $foreignImport = BankImport::factory()
        ->for($owner)
        ->for($ownerAccount)
        ->completed()
        ->create();

    Livewire::actingAs($intruder)
        ->test(ImportBank::class)
        ->set('bankImportId', $foreignImport->id)
        ->call('pollStatus')
        ->assertNotDispatched('csv-import-complete');
});
