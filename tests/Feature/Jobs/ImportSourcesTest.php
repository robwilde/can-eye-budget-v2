<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BankImportStatus;
use App\Enums\ImportPreset;
use App\Enums\TransactionDirection;
use App\Jobs\ImportCsvTransactionsJob;
use App\Livewire\ImportBank;
use App\Models\Account;
use App\Models\BankImport;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CsvImport\BankImportReverter;
use App\Services\CsvImport\BankImportValueMapping;
use App\Services\CsvImport\CsvParserService;
use App\Services\TransactionIngestor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

const SOURCES_QIF = "!Type:Bank\nD15/03/2024\nT-4.50\nPCoffee Shop\nLFood\n^\nD16/03/2024\nT-1,200.00\nPLandlord\nLRent\n^\nD17/03/2024\nT2,500.00\nPEmployer\nLSalary\n^\n";

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->csvImport()->create();
});

function sourcesQifImport(User $user, Account $account, string $content = SOURCES_QIF, ?array $valueMapping = null): BankImport
{
    $path = 'bank-imports/'.bin2hex(random_bytes(6)).'.qif';
    Storage::disk('local')->put($path, $content);

    return BankImport::factory()->for($user)->for($account)->create([
        'stored_path' => $path,
        'preset' => ImportPreset::Qif,
        'column_mapping' => ['qif_date_order' => 'auto'],
        'value_mapping' => $valueMapping,
    ]);
}

function sourcesRunImport(BankImport $import): BankImport
{
    new ImportCsvTransactionsJob($import)->handle(new CsvParserService, app(TransactionIngestor::class));

    return $import->fresh();
}

it('imports transactions from a qif file', function () {
    $import = sourcesQifImport($this->user, $this->account);

    $done = sourcesRunImport($import);

    $transactions = Transaction::query()->where('account_id', $this->account->id)->orderBy('post_date')->get();

    expect($done->status)->toBe(BankImportStatus::Completed)
        ->and($transactions)->toHaveCount(3)
        ->and($transactions->pluck('amount')->all())->toBe([-450, -120000, 250000])
        ->and($transactions->pluck('bank_import_id')->unique()->all())->toBe([$import->id])
        ->and($transactions->pluck('direction')->all())->toBe([TransactionDirection::Debit, TransactionDirection::Debit, TransactionDirection::Credit]);
});

it('imports only the qif categories the user selects', function () {
    $food = Category::factory()->for($this->user)->create(['name' => 'Food']);
    Category::factory()->for($this->user)->create(['name' => 'Rent']);
    $import = sourcesQifImport($this->user, $this->account, SOURCES_QIF, ['categories' => ['Food' => $food->id, 'Rent' => null, 'Salary' => null], 'tags' => []]);

    sourcesRunImport($import);

    $byDescription = Transaction::query()->where('account_id', $this->account->id)->pluck('category_id', 'description');

    expect($byDescription['Coffee Shop'])->toBe($food->id)
        ->and($byDescription['Landlord'])->toBeNull()
        ->and($byDescription['Employer'])->toBeNull();
});

it('maps a ynab export through its preset', function () {
    $csv = "\"Account\",\"Flag\",\"Date\",\"Payee\",\"Category Group/Category\",\"Category Group\",\"Category\",\"Memo\",\"Outflow\",\"Inflow\",\"Cleared\"\n"
        ."\"Everyday\",\"\",\"25/03/2024\",\"Coles\",\"Food: Groceries\",\"Food\",\"Groceries\",\"\",\"\$12.50\",\"\$0.00\",\"Cleared\"\n"
        ."\"Everyday\",\"\",\"26/03/2024\",\"Employer\",\"Income: Salary\",\"Income\",\"Salary\",\"\",\"\$0.00\",\"\$2,000.00\",\"Cleared\"\n";

    Livewire::actingAs($this->user)->test(ImportBank::class)
        ->set('importPreset', 'ynab')
        ->set('accountId', $this->account->id)
        ->set('file', UploadedFile::fake()->createWithContent('ynab.csv', $csv))
        ->call('uploadAndDetectHeaders')
        ->assertSet('mapping.debit', 'Outflow')
        ->assertSet('mapping.credit', 'Inflow')
        ->assertSet('mapping.description', 'Payee')
        ->assertSet('mapping.date_format', 'd/m/Y')
        ->call('confirmImport')
        ->assertHasNoErrors();

    $import = BankImport::query()->sole();
    sourcesRunImport($import);

    $byDescription = Transaction::query()->where('account_id', $this->account->id)->get()->keyBy('description');

    expect($import->preset)->toBe(ImportPreset::Ynab)
        ->and($byDescription['Coles']->only(['amount', 'direction']))->toBe(['amount' => -1250, 'direction' => TransactionDirection::Debit])
        ->and($byDescription['Employer']->only(['amount', 'direction']))->toBe(['amount' => 200000, 'direction' => TransactionDirection::Credit]);
});

it('dedupes a re-imported qif file', function () {
    sourcesRunImport(sourcesQifImport($this->user, $this->account));

    $again = sourcesRunImport(sourcesQifImport($this->user, $this->account));

    expect(Transaction::query()->where('account_id', $this->account->id)->count())->toBe(3)
        ->and($again->imported_count)->toBe(0);
});

it('reverts a qif import without touching other users\' data', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->for($other)->csvImport()->create();
    $otherImport = sourcesRunImport(sourcesQifImport($other, $otherAccount));
    $import = sourcesRunImport(sourcesQifImport($this->user, $this->account));

    app(BankImportReverter::class)->revert($import);

    expect(Transaction::query()->where('account_id', $this->account->id)->count())->toBe(0)
        ->and(Transaction::onlyTrashed()->where('account_id', $this->account->id)->count())->toBe(3)
        ->and(Transaction::query()->where('account_id', $otherAccount->id)->count())->toBe(3)
        ->and($otherImport->fresh()->status)->toBe(BankImportStatus::Completed);
});

it('maps a mint export through its preset', function () {
    $csv = "\"Date\",\"Description\",\"Original Description\",\"Amount\",\"Transaction Type\",\"Category\",\"Account Name\",\"Labels\",\"Notes\"\n"
        ."\"3/25/2024\",\"Coles\",\"COLES 123\",\"12.50\",\"debit\",\"Groceries\",\"Everyday\",\"weekly\",\"\"\n"
        ."\"3/26/2024\",\"Employer\",\"PAY\",\"2,000.00\",\"credit\",\"Income\",\"Everyday\",\"\",\"\"\n";

    Livewire::actingAs($this->user)->test(ImportBank::class)
        ->set('importPreset', 'mint')
        ->set('accountId', $this->account->id)
        ->set('file', UploadedFile::fake()->createWithContent('mint.csv', $csv))
        ->call('uploadAndDetectHeaders')
        ->assertSet('mapping.amount', 'Amount')
        ->assertSet('mapping.tag', 'Labels')
        ->assertSet('mapping.type', 'Transaction Type')
        ->assertSet('mapping.date_format', 'm/d/Y')
        ->assertSet('mapping.sign_convention', 'type_column')
        ->call('confirmImport')
        ->assertHasNoErrors();

    sourcesRunImport(BankImport::query()->sole());

    $byDescription = Transaction::query()->where('account_id', $this->account->id)->get()->keyBy('description');

    expect($byDescription['Coles']->amount)->toBe(-1250)
        ->and($byDescription['Employer']->amount)->toBe(200000);
});

it('maps an actual budget export through its preset', function () {
    $csv = "Account,Date,Payee,Notes,Category,Amount,Cleared\nEveryday,2024-03-25,Coles,,Groceries,-12.50,true\nEveryday,2024-03-26,Employer,,Income,2000.00,true\n";

    Livewire::actingAs($this->user)->test(ImportBank::class)
        ->set('importPreset', 'actual_budget')
        ->set('accountId', $this->account->id)
        ->set('file', UploadedFile::fake()->createWithContent('actual.csv', $csv))
        ->call('uploadAndDetectHeaders')
        ->assertSet('mapping.description', 'Payee')
        ->assertSet('mapping.date_format', 'Y-m-d')
        ->call('confirmImport')
        ->assertHasNoErrors();

    sourcesRunImport(BankImport::query()->sole());

    expect(Transaction::query()->where('account_id', $this->account->id)->orderBy('post_date')->pluck('amount')->all())->toBe([-1250, 200000]);
});

it('does not replace the account\'s saved bank mapping with a preset\'s', function () {
    $this->account->update(['column_mapping' => ['date' => 'Posted', 'amount' => 'Value', 'description' => 'Narrative', 'date_format' => 'd/m/Y']]);
    $csv = "Account,Date,Payee,Notes,Category,Amount,Cleared\nEveryday,2024-03-25,Coles,,Groceries,-12.50,true\n";

    Livewire::actingAs($this->user)->test(ImportBank::class)
        ->set('importPreset', 'actual_budget')
        ->set('accountId', $this->account->id)
        ->set('file', UploadedFile::fake()->createWithContent('actual.csv', $csv))
        ->call('uploadAndDetectHeaders')
        ->call('confirmImport');

    expect($this->account->fresh()->column_mapping['amount'])->toBe('Value');
});

it('offers the qif categories of the file for choosing and imports uncategorised what is left empty', function () {
    $food = Category::factory()->for($this->user)->create(['name' => 'Food']);
    $rent = Category::factory()->for($this->user)->create(['name' => 'Rent']);

    $component = Livewire::actingAs($this->user)->test(ImportBank::class)
        ->set('importPreset', 'qif')
        ->set('accountId', $this->account->id)
        ->set('file', UploadedFile::fake()->createWithContent('export.qif', SOURCES_QIF))
        ->call('uploadAndDetectHeaders')
        ->assertSet('step', 2)
        ->assertSet('cleaning', true)
        ->assertSee('Quicken QIF file')
        ->assertSeeHtml('data-testid="map-category-'.BankImportValueMapping::key('Rent').'"');

    $component->call('mapCategory', BankImportValueMapping::key('Rent'), '')->call('confirmImport')->assertHasNoErrors();

    $import = BankImport::query()->sole();
    sourcesRunImport($import);

    $byDescription = Transaction::query()->where('account_id', $this->account->id)->pluck('category_id', 'description');

    expect($import->preset)->toBe(ImportPreset::Qif)
        ->and($byDescription['Coffee Shop'])->toBe($food->id)
        ->and($byDescription['Landlord'])->toBeNull()
        ->and($rent->id)->not->toBeNull();
});

it('reads the dates of a qif file in the order the user picks', function () {
    $content = "!Type:Bank\nD01/02/2024\nT-1.00\nPOne\n^\n";

    $component = Livewire::actingAs($this->user)->test(ImportBank::class)
        ->set('importPreset', 'qif')
        ->set('accountId', $this->account->id)
        ->set('file', UploadedFile::fake()->createWithContent('export.qif', $content))
        ->call('uploadAndDetectHeaders');

    expect(BankImport::query()->sole()->rows()->sole()->date?->toDateString())->toBe('2024-02-01');

    $component->set('qifDateOrder', 'mdy');

    expect(BankImport::query()->sole()->rows()->sole()->date?->toDateString())->toBe('2024-01-02');
});

it('stays on the first step with a message when the file is not a qif file', function () {
    Livewire::actingAs($this->user)->test(ImportBank::class)
        ->set('importPreset', 'qif')
        ->set('accountId', $this->account->id)
        ->set('file', UploadedFile::fake()->createWithContent('export.qif', "Date,Amount\n1,2\n"))
        ->call('uploadAndDetectHeaders')
        ->assertSet('step', 1)
        ->assertSee('not a QIF file');

    expect(BankImport::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('bank-imports'))->toBe([]);
});

it('does not read a qif file as a csv or the other way round', function () {
    Livewire::actingAs($this->user)->test(ImportBank::class)
        ->set('importPreset', 'bank_csv')
        ->set('accountId', $this->account->id)
        ->set('file', UploadedFile::fake()->createWithContent('export.qif', SOURCES_QIF))
        ->call('uploadAndDetectHeaders')
        ->assertHasErrors('file');

    Livewire::actingAs($this->user)->test(ImportBank::class)
        ->set('importPreset', 'qif')
        ->set('accountId', $this->account->id)
        ->set('file', UploadedFile::fake()->createWithContent('statement.csv', SOURCES_QIF))
        ->call('uploadAndDetectHeaders')
        ->assertHasErrors('file');
});

it('blocks a qif import while a row is invalid', function () {
    $component = Livewire::actingAs($this->user)->test(ImportBank::class)
        ->set('importPreset', 'qif')
        ->set('accountId', $this->account->id)
        ->set('file', UploadedFile::fake()->createWithContent('export.qif', "!Type:Bank\nD01/03/2024\nTabc\nPOne\n^\n"))
        ->call('uploadAndDetectHeaders')
        ->call('confirmImport')
        ->assertHasErrors('rows');

    Queue::assertNotPushed(ImportCsvTransactionsJob::class);

    $row = BankImport::query()->sole()->rows()->sole();
    $component->call('updateRow', $row->id, 'amount', '-4.50')->call('confirmImport')->assertHasNoErrors();

    Queue::assertPushed(ImportCsvTransactionsJob::class);
});

it('records the preset of a csv import', function () {
    Livewire::actingAs($this->user)->test(ImportBank::class)
        ->set('accountId', $this->account->id)
        ->set('file', UploadedFile::fake()->createWithContent('bank.csv', "Date,Description,Amount\n15/03/2024,Shop,-4.50\n"))
        ->call('uploadAndDetectHeaders');

    expect(BankImport::query()->sole()->preset)->toBe(ImportPreset::BankCsv);
});

it('accepts a qif file whose type the server cannot tell from its content', function () {
    Livewire::actingAs($this->user)->test(ImportBank::class)
        ->set('importPreset', 'qif')
        ->set('accountId', $this->account->id)
        ->set('file', UploadedFile::fake()->createWithContent('export.qif', SOURCES_QIF)->mimeType('application/octet-stream'))
        ->assertHasNoErrors()
        ->call('uploadAndDetectHeaders')
        ->assertHasNoErrors()
        ->assertSet('step', 2);
});

it('refuses an export that holds more than one account', function () {
    $csv = "Date,Description,Original Description,Amount,Transaction Type,Category,Account Name,Labels,Notes\n"
        ."\"3/25/2024\",\"Coles\",\"\",\"12.50\",\"debit\",\"Groceries\",\"Everyday\",\"\",\"\"\n"
        ."\"3/26/2024\",\"Pay\",\"\",\"500.00\",\"credit\",\"Income\",\"Visa\",\"\",\"\"\n";

    Livewire::actingAs($this->user)->test(ImportBank::class)
        ->set('importPreset', 'mint')
        ->set('accountId', $this->account->id)
        ->set('file', UploadedFile::fake()->createWithContent('mint.csv', $csv))
        ->call('uploadAndDetectHeaders')
        ->assertSet('step', 1)
        ->assertSee('more than one account');

    expect(BankImport::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('bank-imports'))->toBe([]);
});

it('shows and reads the dates of a month-first qif file the way the file writes them', function () {
    $content = "!Type:Bank\nD12/31/2024\nT-1.00\nPOne\n^\nD01/05/2024\nT-2.00\nPTwo\n^\nD02/30/2024\nT-3.00\nPThree\n^\n";

    $component = Livewire::actingAs($this->user)->test(ImportBank::class)
        ->set('importPreset', 'qif')
        ->set('accountId', $this->account->id)
        ->set('file', UploadedFile::fake()->createWithContent('export.qif', $content))
        ->call('uploadAndDetectHeaders')
        ->assertSeeHtml('value="12/31/2024"')
        ->assertSeeHtml('value="01/05/2024"');

    $row = BankImport::query()->sole()->rows()->where('row_number', 3)->sole();
    $component->call('updateRow', $row->id, 'date', '02/03/2024');

    expect($row->fresh()->date?->toDateString())->toBe('2024-02-03');
});

it('cannot close the rows table of a qif import', function () {
    Livewire::actingAs($this->user)->test(ImportBank::class)
        ->set('importPreset', 'qif')
        ->set('accountId', $this->account->id)
        ->set('file', UploadedFile::fake()->createWithContent('export.qif', SOURCES_QIF))
        ->call('uploadAndDetectHeaders')
        ->assertDontSeeHtml('wire:click="closeRows"');
});
