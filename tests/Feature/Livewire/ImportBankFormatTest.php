<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Jobs\ImportCsvTransactionsJob;
use App\Livewire\ImportBank;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->csvImport()->create();
});

function formatUpload(User $user, Account $account, string $content)
{
    return Livewire::actingAs($user)->test(ImportBank::class)
        ->set('accountChoice', 'existing')
        ->set('accountId', $account->id)
        ->set('file', UploadedFile::fake()->createWithContent('statement.csv', $content))
        ->call('uploadAndDetectHeaders');
}

it('offers only the date formats that fit the file and pre-selects the first', function () {
    $component = formatUpload($this->user, $this->account, "Date,Description,Amount\n15/03/2024,Coffee,-4.50\n");

    $component->assertSet('mapping.date_format', 'd/m/Y')
        ->assertSeeHtml('import-bank-date-format')
        ->assertSee('DD/MM/YYYY')
        ->assertDontSee('MM/DD/YYYY');
});

it('validates dates against the chosen date format and blocks confirm while a row is invalid', function () {
    $component = formatUpload($this->user, $this->account, "Date,Description,Amount\n15/03/2024,Coffee,-4.50\n");

    $component->set('mapping.date_format', 'm/d/Y')
        ->assertSee('is invalid')
        ->call('confirmImport')
        ->assertHasErrors('mapping.date_format');

    Queue::assertNotPushed(ImportCsvTransactionsJob::class);

    $component->set('mapping.date_format', 'd/m/Y')->call('confirmImport')->assertHasNoErrors();

    Queue::assertPushed(ImportCsvTransactionsJob::class);
});

it('requires rows to skip to be fewer than the line count', function () {
    $component = formatUpload($this->user, $this->account, "Date,Description,Amount\n15/03/2024,Coffee,-4.50\n");

    $component->set('mapping.skip_rows', '2')->call('confirmImport')->assertHasErrors('mapping.skip_rows');

    Queue::assertNotPushed(ImportCsvTransactionsJob::class);
});

it('reads a semicolon file and re-reads the headers when the delimiter changes', function () {
    $component = formatUpload($this->user, $this->account, "Date;Description;Amount\n15/03/2024;Coffee;-4,50\n");

    $component->assertSet('mapping.delimiter', ';')->assertSet('headers', ['Date', 'Description', 'Amount'])
        ->set('mapping.delimiter', ',')
        ->assertSet('headers', ['Date;Description;Amount'])
        ->set('mapping.delimiter', ';')
        ->assertSet('headers', ['Date', 'Description', 'Amount'])
        ->assertSet('mapping.amount', 'Amount')
        ->assertSet('mapping.delimiter', ';');
});

it('rejects rows dated after today in the preview', function () {
    $tomorrow = now()->addDay()->format('d/m/Y');
    $component = formatUpload($this->user, $this->account, "Date,Description,Amount\n{$tomorrow},Future,-4.50\n");

    $component->assertSeeHtml('import-bank-invalid-rows')->assertSee('in the future')->call('confirmImport')->assertHasErrors('mapping.date_format');
});

it('remembers the chosen format on the user\'s own account', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->for($other)->csvImport()->create(['column_mapping' => ['date' => 'Other']]);

    formatUpload($this->user, $this->account, "Date;Description;Amount\n2024-03-15;Coffee;-4,50\n")
        ->set('mapping.number_format', 'dot_comma')
        ->call('confirmImport');

    $saved = $this->account->fresh()->column_mapping;

    expect($saved['date_format'])->toBe('Y-m-d')
        ->and($saved['number_format'])->toBe('dot_comma')
        ->and($saved['delimiter'])->toBe(';')
        ->and($otherAccount->fresh()->column_mapping)->toBe(['date' => 'Other']);

    $next = formatUpload($this->user, $this->account, "Date;Description;Amount\n2024-04-15;Tea;-3,00\n");

    $next->assertSet('mapping.date_format', 'Y-m-d')->assertSet('mapping.number_format', 'dot_comma')->assertSet('mapping.delimiter', ';');
});

it('requires a type column when the sign comes from one', function () {
    $component = formatUpload($this->user, $this->account, "Date,Description,Amount,Kind\n15/03/2024,Coffee,4.50,debit\n");

    $component->set('mapping.sign_convention', 'type_column')->call('confirmImport')->assertHasErrors('mapping.type');
});

it('lists every date format when none fits all the samples', function () {
    $component = formatUpload($this->user, $this->account, "Date,Description,Amount\n15/03/2024,Coffee,-4.50\nPending,Hold,-1.00\n");

    $component->assertSee('MM/DD/YYYY')->assertSee('YYYY-MM-DD');
});

it('imports a day-month-name date and a plus-signed amount', function () {
    $component = formatUpload($this->user, $this->account, "Date,Description,Amount\n15-Mar-2024,Salary,+12.30\n");

    $component->assertSet('mapping.date_format', 'd-M-Y')->call('confirmImport')->assertHasNoErrors();

    Queue::assertPushed(ImportCsvTransactionsJob::class);
});

it('tells the user when the saved format cannot read the file', function () {
    $this->account->update(['column_mapping' => ['date_format' => 'd/m/Y', 'skip_rows' => '9', 'delimiter' => ',']]);

    formatUpload($this->user, $this->account, "Date,Description,Amount\n15/03/2024,Coffee,-4.50\n")
        ->assertSee('could not be read with its saved format settings')
        ->assertSet('step', 1);
});
