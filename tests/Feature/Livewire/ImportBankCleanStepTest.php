<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Jobs\ImportCsvTransactionsJob;
use App\Livewire\ImportBank;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankImport;
use App\Models\BankImportRow;
use App\Models\Category;
use App\Models\Tag;
use App\Models\User;
use App\Services\CsvImport\BankImportValueMapping;
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

function cleanUpload(User $user, Account $account, string $content)
{
    return Livewire::actingAs($user)->test(ImportBank::class)
        ->set('accountChoice', 'existing')
        ->set('accountId', $account->id)
        ->set('file', UploadedFile::fake()->createWithContent('statement.csv', $content))
        ->call('uploadAndDetectHeaders')
        ->call('reviewRows');
}

it('filters to error rows and blocks confirm while a row is invalid', function () {
    $component = cleanUpload($this->user, $this->account, "Date,Description,Amount\n15/03/2024,Good row,-4.50\n16/03/2024,Broken row,abc\n");

    $component->set('errorsOnly', true)
        ->assertSee('Broken row')
        ->assertDontSee('Good row')
        ->assertSeeHtml('aria-invalid="true"')
        ->call('confirmImport')
        ->assertHasErrors('mapping.date_format');

    Queue::assertNotPushed(ImportCsvTransactionsJob::class);
    expect(BankImportRow::query()->invalid()->count())->toBe(1);
});

it('revalidates a row after a cell edit', function () {
    $component = cleanUpload($this->user, $this->account, "Date,Description,Amount\n15/03/2024,Broken row,abc\n");
    $row = BankImportRow::query()->sole();

    $component->call('updateRow', $row->id, 'amount', '12.30')->assertHasNoErrors();

    expect($row->fresh()->amount)->toBe(1230)
        ->and($row->fresh()->date?->toDateString())->toBe('2024-03-15')
        ->and($row->fresh()->isValid())->toBeTrue();

    $component->call('confirmImport')->assertHasNoErrors();

    Queue::assertPushed(ImportCsvTransactionsJob::class);
});

it('keeps a row invalid when the edited cell is still wrong', function () {
    $component = cleanUpload($this->user, $this->account, "Date,Description,Amount\n15/03/2024,Row,-4.50\n");
    $row = BankImportRow::query()->sole();

    $component->call('updateRow', $row->id, 'date', '31/02/2024')->call('updateRow', $row->id, 'amount', 'nope');

    expect($row->fresh()->isValid())->toBeFalse()
        ->and(array_keys($row->fresh()->errors))->toEqualCanonicalizing(['date', 'amount'])
        ->and($row->fresh()->raw_date)->toBe('31/02/2024');

    $component->call('updateRow', $row->id, 'date', '16/03/2024')->call('updateRow', $row->id, 'amount', '-9.99');

    expect($row->fresh()->isValid())->toBeTrue()
        ->and($row->fresh()->date?->toDateString())->toBe('2024-03-16')
        ->and($row->fresh()->amount)->toBe(-999);
});

it('refuses edits to another user\'s import rows and audits own edits', function () {
    $other = User::factory()->create();
    $otherImport = BankImport::factory()->for($other)->for(Account::factory()->for($other)->csvImport()->create())->create();
    $foreign = BankImportRow::factory()->create(['bank_import_id' => $otherImport->id, 'description' => 'Theirs']);

    $component = cleanUpload($this->user, $this->account, "Date,Description,Amount\n15/03/2024,Mine,-4.50\n");

    $component->call('updateRow', $foreign->id, 'description', 'Hacked')->assertHasErrors('rows');

    expect($foreign->fresh()->description)->toBe('Theirs')
        ->and(AuditEvent::query()->where('action', 'livewire.import-bank.updateRow')->latest('id')->first()->outcome)->toBe(AuditOutcome::Failure);

    $own = BankImportRow::query()->where('bank_import_id', '!=', $otherImport->id)->sole();
    $component->call('updateRow', $own->id, 'description', 'Renamed');

    $event = AuditEvent::query()->where('action', 'livewire.import-bank.updateRow')->latest('id')->first();

    expect($own->fresh()->description)->toBe('Renamed')
        ->and($event->user_id)->toBe($this->user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success);
});

it('ignores an edit to a field that is not editable', function () {
    $component = cleanUpload($this->user, $this->account, "Date,Description,Amount\n15/03/2024,Mine,-4.50\n");
    $row = BankImportRow::query()->sole();

    $component->call('updateRow', $row->id, 'bank_import_id', '999')->assertHasErrors('rows');

    expect($row->fresh()->bank_import_id)->not->toBe(999);
});

it('shows the first page of rows and the invalid count', function () {
    $lines = collect(range(1, 30))->map(fn (int $i): string => sprintf('15/03/2024,Row %d,-%d.00', $i, $i))->implode("\n");

    $component = cleanUpload($this->user, $this->account, "Date,Description,Amount\n{$lines}\n");

    $component->assertSee('Row 25')->assertDontSee('Row 26')->assertSee('30 rows')->assertSee('0 invalid');

    expect(BankImportRow::query()->count())->toBe(30);
});

it('throws away the edited rows when the mapping changes', function () {
    $component = cleanUpload($this->user, $this->account, "Date,Description,Amount\n15/03/2024,Mine,-4.50\n");

    expect(BankImportRow::query()->count())->toBe(1);

    $component->set('mapping.number_format', 'dot_comma');

    expect(BankImportRow::query()->count())->toBe(0)
        ->and(BankImport::query()->sole()->value_mapping)->toBeNull();
});

it('maps file categories and tags only to the user\'s own', function () {
    $mine = Category::factory()->for($this->user)->create(['name' => 'Groceries']);
    $theirs = Category::factory()->for(User::factory()->create())->create();
    $myTag = Tag::factory()->for($this->user)->create(['name' => 'trip']);
    $theirTag = Tag::factory()->for(User::factory()->create())->create();

    $component = cleanUpload($this->user, $this->account, "Date,Description,Amount,Category,Tags\n15/03/2024,Shop,-4.50,Food,holiday\n");
    $import = BankImport::query()->sole();
    $categoryKey = BankImportValueMapping::key('Food');
    $tagKey = BankImportValueMapping::key('holiday');

    $component->call('mapCategory', $categoryKey, (string) $theirs->id)->assertHasErrors('value_mapping');
    $component->call('mapTag', $tagKey, (string) $theirTag->id)->assertHasErrors('value_mapping');

    expect($import->fresh()->value_mapping['categories']['Food'])->toBeNull();

    $component->call('mapCategory', $categoryKey, (string) $mine->id)->call('mapTag', $tagKey, (string) $myTag->id);

    expect($import->fresh()->value_mapping['categories']['Food'])->toBe($mine->id)
        ->and($import->fresh()->value_mapping['tags']['holiday'])->toBe(['type' => 'existing', 'id' => $myTag->id]);
});

it('blocks confirm when a saved mapping points at something that is gone', function () {
    $category = Category::factory()->for($this->user)->create(['name' => 'Groceries']);
    $component = cleanUpload($this->user, $this->account, "Date,Description,Amount,Category\n15/03/2024,Shop,-4.50,Food\n");
    $component->call('mapCategory', BankImportValueMapping::key('Food'), (string) $category->id);
    $category->delete();

    $component->call('confirmImport')->assertHasErrors('value_mapping');

    Queue::assertNotPushed(ImportCsvTransactionsJob::class);
});

it('starts with the categories and tags that already match by name', function () {
    $category = Category::factory()->for($this->user)->create(['name' => 'Groceries']);
    $tag = Tag::factory()->for($this->user)->create(['name' => 'Holiday']);

    cleanUpload($this->user, $this->account, "Date,Description,Amount,Category,Tags\n15/03/2024,Shop,-4.50,Groceries,holiday\n15/03/2024,Other,-1.00,Spaceships,\n");

    $mapping = BankImport::query()->sole()->value_mapping;

    expect($mapping['categories']['Groceries'])->toBe($category->id)
        ->and($mapping['categories']['Spaceships'])->toBeNull()
        ->and($mapping['tags']['holiday'])->toBe(['type' => 'existing', 'id' => $tag->id]);
});

it('keeps edits and choices when the rows table is closed and opened again', function () {
    $component = cleanUpload($this->user, $this->account, "Date,Description,Amount\n15/03/2024,Broken row,abc\n");
    $row = BankImportRow::query()->sole();

    $component->call('updateRow', $row->id, 'amount', '12.30')->call('closeRows')->call('reviewRows');

    expect($row->fresh()->amount)->toBe(1230)
        ->and(BankImportRow::query()->count())->toBe(1);
});

it('returns to the first page when errors only is switched on', function () {
    $lines = collect(range(1, 30))->map(fn (int $i): string => sprintf('15/03/2024,Row %d,%s', $i, $i === 3 ? 'abc' : '-1.00'))->implode("\n");

    cleanUpload($this->user, $this->account, "Date,Description,Amount\n{$lines}\n")
        ->call('gotoPage', 2, 'rowsPage')
        ->set('errorsOnly', true)
        ->assertSee('Row 3');
});

it('closes the rows table when starting over', function () {
    $component = cleanUpload($this->user, $this->account, "Date,Description,Amount\n15/03/2024,Mine,-4.50\n");

    $component->call('startOver')->assertSet('cleaning', false)->assertSet('errorsOnly', false);
});

it('records a fixed row as a success even after a blocked confirm', function () {
    $component = cleanUpload($this->user, $this->account, "Date,Description,Amount\n15/03/2024,Broken row,abc\n");
    $row = BankImportRow::query()->sole();

    $component->call('confirmImport')->assertHasErrors('mapping.date_format');
    $component->call('updateRow', $row->id, 'amount', '12.30');

    expect(AuditEvent::query()->where('action', 'livewire.import-bank.updateRow')->latest('id')->first()->outcome)->toBe(AuditOutcome::Success);
    $component->assertHasNoErrors();
});

it('opens the rows table when confirm is blocked by a choice that is gone', function () {
    $category = Category::factory()->for($this->user)->create(['name' => 'Groceries']);
    $component = cleanUpload($this->user, $this->account, "Date,Description,Amount,Category\n15/03/2024,Shop,-4.50,Food\n");
    $component->call('mapCategory', BankImportValueMapping::key('Food'), (string) $category->id)->call('closeRows');
    $category->delete();

    $component->call('confirmImport')->assertSet('cleaning', true)->assertSeeHtml('data-testid="import-bank-value-mapping"');
});

it('stops reporting invalid rows in the summary once they are fixed', function () {
    $component = cleanUpload($this->user, $this->account, "Date,Description,Amount\n15/03/2024,Broken row,abc\n");
    $row = BankImportRow::query()->sole();

    $component->assertSeeHtml('data-testid="import-bank-invalid-rows"')
        ->call('updateRow', $row->id, 'amount', '12.30')
        ->assertDontSeeHtml('data-testid="import-bank-invalid-rows"');
});

it('shows amounts in the number format of the file so an edit can be typed back', function () {
    $component = cleanUpload($this->user, $this->account, "Date,Description,Amount\n15/03/2024,Shop,\"-1.234,50\"\n")
        ->set('mapping.number_format', 'dot_comma')
        ->call('reviewRows');
    $row = BankImportRow::query()->sole();

    $component->assertSeeHtml('value="-1234,50"')->call('updateRow', $row->id, 'amount', '-9,99');

    expect($row->fresh()->amount)->toBe(-999)->and($row->fresh()->isValid())->toBeTrue();
});
