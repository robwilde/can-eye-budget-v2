<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BankImportStatus;
use App\Enums\ImportSource;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Jobs\ImportCsvTransactionsJob;
use App\Livewire\ImportBank;
use App\Models\Account;
use App\Models\BankImport;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CsvImport\CsvColumnMapper;
use App\Services\CsvImport\CsvParserService;
use App\Services\TransactionIngestor;
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
        ->set('newAccountBalance', '1686.19')
        ->set('file', fixtureUpload())
        ->call('uploadAndDetectHeaders')
        ->assertSet('step', 2);

    $account = Account::query()->where('user_id', $user->id)->first();

    expect($account)->not->toBeNull()
        ->and($account->name)->toBe('Westpac Choice')
        ->and($account->account_last4)->toBe('4599')
        ->and($account->import_source)->toBe(ImportSource::Csv)
        ->and($account->balance)->toBe(168_619)
        ->and($account->balance_source)->toBe(ImportSource::Csv)
        ->and($account->balance_updated_at)->not->toBeNull();
});

test('inline account creation defaults balance to zero when left blank', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'new')
        ->set('newAccountName', 'No Balance Account')
        ->set('newAccountLast4', '0000')
        ->set('newAccountBalance', '')
        ->set('file', fixtureUpload())
        ->call('uploadAndDetectHeaders')
        ->assertSet('step', 2);

    expect(Account::query()->where('user_id', $user->id)->first()->balance)->toBe(0);
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

test('end-to-end: confirming a Beyond Bank upload imports real transactions into the account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->csvImport()->create();

    // Drive the wizard exactly as a user would: upload, auto-map, confirm.
    Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'existing')
        ->set('accountId', $account->id)
        ->set('file', fixtureUpload('StatementCsv-BeyondBank.csv'))
        ->call('uploadAndDetectHeaders')
        ->assertSet('step', 2)
        ->call('confirmImport')
        ->assertSet('step', 3);

    // confirmImport queues the import (faked in beforeEach); run it for real to
    // complete the process and prove the wiring produces transactions.
    $bankImport = BankImport::query()->where('user_id', $user->id)->latest('id')->firstOrFail();

    new ImportCsvTransactionsJob($bankImport)->handle(new CsvParserService(), app(TransactionIngestor::class));

    $bankImport->refresh();

    expect($bankImport->status)->toBe(BankImportStatus::Completed)
        ->and($bankImport->imported_count)->toBeGreaterThan(0);

    // Every imported row landed as a CSV-sourced transaction on the account.
    expect($account->transactions()->where('source', TransactionSource::Csv)->count())
        ->toBe($bankImport->imported_count);

    // A known credit parsed end-to-end: the Osko salary of +$1,500.00, stored
    // as 150000 cents with a Credit direction.
    expect($account->transactions()
        ->where('direction', TransactionDirection::Credit)
        ->where('amount', 150_000)
        ->exists())->toBeTrue();
});

test('confirmImport prefills and applies the statement closing balance for an existing account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->csvImport()->create(['balance' => 0]);

    $csv = "Date,Description,Amount,Balance\n01/06/2026,Coffee,-5.00,1000.00\n03/06/2026,Pay,2000.00,3000.00\n";
    $file = UploadedFile::fake()->createWithContent('statement.csv', $csv);

    $component = Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'existing')
        ->set('accountId', $account->id)
        ->set('file', $file)
        ->call('uploadAndDetectHeaders')
        ->set('mapping.'.CsvColumnMapper::FIELD_BALANCE, 'Balance');

    expect($component->get('currentBalance'))->toBe('3000.00');

    $component->call('confirmImport')->assertSet('step', 3);

    expect($account->refresh()->balance)->toBe(300000);
});

test('a manually entered current balance overrides the prefilled closing balance', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->csvImport()->create(['balance' => 0]);

    $csv = "Date,Description,Amount,Balance\n01/06/2026,Coffee,-5.00,1000.00\n03/06/2026,Pay,2000.00,3000.00\n";
    $file = UploadedFile::fake()->createWithContent('statement.csv', $csv);

    Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'existing')
        ->set('accountId', $account->id)
        ->set('file', $file)
        ->call('uploadAndDetectHeaders')
        ->set('mapping.'.CsvColumnMapper::FIELD_BALANCE, 'Balance')
        ->set('currentBalance', '1234.56')
        ->call('confirmImport')
        ->assertSet('step', 3);

    expect($account->refresh()->balance)->toBe(123456)
        ->and($account->balance_source)->toBe(ImportSource::Csv)
        ->and($account->balance_updated_at)->not->toBeNull();
});

test('changing the balance column re-derives the prefilled current balance', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->csvImport()->create(['balance' => 0]);

    $csv = "Date,Description,Amount,Balance,RunningTotal\n01/06/2026,Coffee,-5.00,1000.00,2000.00\n03/06/2026,Pay,2000.00,3000.00,5000.00\n";
    $file = UploadedFile::fake()->createWithContent('statement.csv', $csv);

    $component = Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'existing')
        ->set('accountId', $account->id)
        ->set('file', $file)
        ->call('uploadAndDetectHeaders')
        ->set('mapping.'.CsvColumnMapper::FIELD_BALANCE, 'Balance');

    expect($component->get('currentBalance'))->toBe('3000.00');

    $component->set('mapping.'.CsvColumnMapper::FIELD_BALANCE, 'RunningTotal');

    expect($component->get('currentBalance'))->toBe('5000.00');
});

test('a manually entered balance is preserved when the mapping changes', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->csvImport()->create(['balance' => 0]);

    $csv = "Date,Description,Amount,Balance,RunningTotal\n01/06/2026,Coffee,-5.00,1000.00,2000.00\n03/06/2026,Pay,2000.00,3000.00,5000.00\n";
    $file = UploadedFile::fake()->createWithContent('statement.csv', $csv);

    $component = Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'existing')
        ->set('accountId', $account->id)
        ->set('file', $file)
        ->call('uploadAndDetectHeaders')
        ->set('mapping.'.CsvColumnMapper::FIELD_BALANCE, 'Balance')
        ->set('currentBalance', '99.99')
        ->set('mapping.'.CsvColumnMapper::FIELD_BALANCE, 'RunningTotal');

    expect($component->get('currentBalance'))->toBe('99.99');
});

// ── lastImportedDate computed property (#313) ─────────────────────────────────

test('selecting an existing account with a prior csv transaction shows last-imported hint', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->csvImport()->create();
    Transaction::factory()->for($user)->for($account)->fromCsv()->create(['post_date' => '2026-07-09']);

    Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'existing')
        ->set('accountId', $account->id)
        ->assertSeeHtml('data-testid="import-bank-last-imported"')
        ->assertSeeHtml('09/07/2026');
});

test('selecting an existing account with no imported transactions shows no last-imported hint', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->csvImport()->create();

    Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'existing')
        ->set('accountId', $account->id)
        ->assertDontSeeHtml('data-testid="import-bank-last-imported"');
});

// ── date continuity gap check (#316) ─────────────────────────────────────────

test('uploading a csv whose earliest date is after the last import shows a gap warning', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->csvImport()->create();

    // Last imported transaction is 5 days before the CSV's earliest row (01/01/2026)
    Transaction::factory()->for($user)->for($account)->fromCsv()->create(['post_date' => '2025-12-27']);

    Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'existing')
        ->set('accountId', $account->id)
        ->set('file', fixtureUpload())
        ->call('uploadAndDetectHeaders')
        ->assertSeeHtml('data-testid="import-bank-continuity-gap"')
        ->assertSeeHtml('01/01/2026')
        ->assertSeeHtml('27/12/2025');
});

test('uploading a csv that overlaps the last import shows a continuous confirmation', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->csvImport()->create();

    // Last imported transaction is inside the CSV range (CSV starts 01/01/2026)
    Transaction::factory()->for($user)->for($account)->fromCsv()->create(['post_date' => '2026-01-02']);

    Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'existing')
        ->set('accountId', $account->id)
        ->set('file', fixtureUpload())
        ->call('uploadAndDetectHeaders')
        ->assertSeeHtml('data-testid="import-bank-continuity-ok"')
        ->assertDontSeeHtml('data-testid="import-bank-continuity-gap"');
});

test('uploading a csv for an account with no prior imports shows no continuity message', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->csvImport()->create();

    Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'existing')
        ->set('accountId', $account->id)
        ->set('file', fixtureUpload())
        ->call('uploadAndDetectHeaders')
        ->assertDontSeeHtml('data-testid="import-bank-continuity-gap"')
        ->assertDontSeeHtml('data-testid="import-bank-continuity-ok"');
});
