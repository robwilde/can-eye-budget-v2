<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\CsvFormat;
use App\Enums\BankImportStatus;
use App\Enums\CsvSignConvention;
use App\Enums\TransactionDirection;
use App\Jobs\ImportCsvTransactionsJob;
use App\Models\Account;
use App\Models\BankImport;
use App\Models\Category;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CsvImport\BankImportRowBuilder;
use App\Services\CsvImport\BankImportRowEditor;
use App\Services\CsvImport\BankImportValueMapping;
use App\Services\CsvImport\CsvColumnMapper;
use App\Services\CsvImport\CsvParserService;
use App\Services\TransactionIngestor;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->csvImport()->create();
});

function valueMappedImport(User $user, Account $account, string $content, ?array $valueMapping): BankImport
{
    $path = 'bank-imports/'.bin2hex(random_bytes(6)).'.csv';
    Storage::disk('local')->put($path, $content);

    $import = BankImport::factory()->for($user)->for($account)->create([
        'stored_path' => $path,
        'column_mapping' => [
            CsvColumnMapper::FIELD_DATE => 'Date',
            CsvColumnMapper::FIELD_DESCRIPTION => 'Description',
            CsvColumnMapper::FIELD_AMOUNT => 'Amount',
            CsvColumnMapper::FIELD_CATEGORY => 'Category',
            CsvColumnMapper::FIELD_TAG => 'Tags',
            ...new CsvFormat()->toMapping(),
        ],
        'value_mapping' => $valueMapping,
    ]);

    app(BankImportRowBuilder::class)->build($import);

    return $import->fresh();
}

function runValueImport(BankImport $import): BankImport
{
    new ImportCsvTransactionsJob($import)->handle(new CsvParserService, app(TransactionIngestor::class));

    return $import->fresh();
}

it('applies mapped categories to imported transactions', function () {
    $groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries']);
    $import = valueMappedImport($this->user, $this->account, "Date,Description,Amount,Category,Tags\n15/03/2024,Shop,-4.50,Food,\n", ['categories' => ['Food' => $groceries->id], 'tags' => []]);

    runValueImport($import);

    expect(Transaction::query()->where('account_id', $this->account->id)->sole()->category_id)->toBe($groceries->id);
});

it('leaves unmapped file categories empty', function () {
    Category::factory()->for($this->user)->create(['name' => 'Food']);
    $import = valueMappedImport($this->user, $this->account, "Date,Description,Amount,Category,Tags\n15/03/2024,Shop,-4.50,Food,\n", ['categories' => ['Food' => null], 'tags' => []]);

    $done = runValueImport($import);

    expect(Transaction::query()->where('account_id', $this->account->id)->sole()->category_id)->toBeNull()
        ->and($done->unresolved_categories)->toBeNull();
});

it('creates and attaches mapped tags', function () {
    $import = valueMappedImport($this->user, $this->account, "Date,Description,Amount,Category,Tags\n15/03/2024,Hotel,-90.00,,holiday\n16/03/2024,Flight,-300.00,,holiday\n", ['categories' => [], 'tags' => ['holiday' => ['type' => 'new']]]);

    runValueImport($import);

    $tag = Tag::query()->where('user_id', $this->user->id)->where('name', 'holiday')->sole();

    expect($tag->transactions()->count())->toBe(2)
        ->and(Tag::query()->where('name', 'holiday')->count())->toBe(1);
});

it('attaches an existing tag, several tags from one cell, and nothing for an unmapped tag', function () {
    $trip = Tag::factory()->for($this->user)->create(['name' => 'trip']);
    $import = valueMappedImport($this->user, $this->account, "Date,Description,Amount,Category,Tags\n15/03/2024,Hotel,-90.00,,\"trip, holiday | skipme\"\n", [
        'categories' => [],
        'tags' => ['trip' => ['type' => 'existing', 'id' => $trip->id], 'holiday' => ['type' => 'new'], 'skipme' => null],
    ]);

    runValueImport($import);

    $transaction = Transaction::query()->where('account_id', $this->account->id)->sole();

    expect($transaction->tags()->pluck('name')->sort()->values()->all())->toBe(['holiday', 'trip'])
        ->and(Tag::query()->where('name', 'skipme')->exists())->toBeFalse();
});

it('does not attach another user\'s tag or category', function () {
    $theirs = Tag::factory()->for(User::factory()->create())->create();
    $theirCategory = Category::factory()->for(User::factory()->create())->create();
    $import = valueMappedImport($this->user, $this->account, "Date,Description,Amount,Category,Tags\n15/03/2024,Hotel,-90.00,Food,trip\n", [
        'categories' => ['Food' => $theirCategory->id],
        'tags' => ['trip' => ['type' => 'existing', 'id' => $theirs->id]],
    ]);

    runValueImport($import);

    $transaction = Transaction::query()->where('account_id', $this->account->id)->sole();

    expect($transaction->category_id)->toBeNull()
        ->and($transaction->tags()->count())->toBe(0);
});

it('imports the edited cells and skips rows that are still invalid', function () {
    $import = valueMappedImport($this->user, $this->account, "Date,Description,Amount,Category,Tags\n15/03/2024,Original,-4.50,,\n16/03/2024,Broken,abc,,\n", null);
    $row = $import->rows()->where('row_number', 1)->sole();
    $row->update(['description' => 'Edited', 'amount' => -999]);

    $done = runValueImport($import);

    expect($done->status)->toBe(BankImportStatus::Completed)
        ->and($done->imported_count)->toBe(1)
        ->and(Transaction::query()->where('account_id', $this->account->id)->sole()->only(['description', 'amount']))->toBe(['description' => 'Edited', 'amount' => -999]);
});

it('falls back to name matching when the import has no value mapping', function () {
    $groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries']);
    $import = valueMappedImport($this->user, $this->account, "Date,Description,Amount,Category,Tags\n15/03/2024,Shop,-4.50,Groceries,\n16/03/2024,Other,-1.00,Spaceships,\n", null);

    $done = runValueImport($import);

    expect(Transaction::query()->where('description', 'Shop')->sole()->category_id)->toBe($groceries->id)
        ->and($done->unresolved_categories)->toBe([['value' => 'Spaceships', 'rows' => 1]]);
});

it('builds rows with the cell that failed named, and replaces earlier rows', function () {
    $import = valueMappedImport($this->user, $this->account, "Date,Description,Amount,Category,Tags\n15/03/2024,Fine,-4.50,,\n99/99/2024,Bad date,-1.00,,\n16/03/2024,Bad amount,zzz,,\n,Missing date,-1.00,,\n", null);

    $rows = $import->rows()->orderBy('row_number')->get();

    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('errors')->map(fn ($e) => $e === null ? null : array_keys($e)[0])->all())->toBe([null, 'date', 'amount', 'date'])
        ->and($rows[1]->raw_date)->toBe('99/99/2024')
        ->and($rows[1]->amount)->toBe(-100)
        ->and($rows[2]->raw_amount)->toBe('zzz')
        ->and($rows[2]->date?->toDateString())->toBe('2024-03-16');

    app(BankImportRowBuilder::class)->build($import);

    expect($import->rows()->count())->toBe(4);
});

it('attaches a tag whatever the casing in the cell', function () {
    $import = valueMappedImport($this->user, $this->account, "Date,Description,Amount,Category,Tags\n15/03/2024,Hotel,-90.00,,Holiday\n16/03/2024,Flight,-300.00,,holiday\n", ['categories' => [], 'tags' => ['Holiday' => ['type' => 'new']]]);

    runValueImport($import);

    expect(Tag::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and(Tag::query()->where('user_id', $this->user->id)->sole()->transactions()->count())->toBe(2);
});

it('lists file categories that differ only by case separately', function () {
    $import = valueMappedImport($this->user, $this->account, "Date,Description,Amount,Category,Tags\n15/03/2024,One,-1.00,Food,\n16/03/2024,Two,-2.00,food,\n", null);

    expect(app(BankImportValueMapping::class)->categoryValues($import))->toEqualCanonicalizing(['Food', 'food']);
});

it('attaches existing tags when the import was never reviewed', function () {
    $trip = Tag::factory()->for($this->user)->create(['name' => 'trip']);
    $import = valueMappedImport($this->user, $this->account, "Date,Description,Amount,Category,Tags\n15/03/2024,Hotel,-90.00,,\"trip, unknown\"\n", null);

    runValueImport($import);

    expect(Transaction::query()->where('account_id', $this->account->id)->sole()->tags()->pluck('tags.id')->all())->toBe([$trip->id])
        ->and(Tag::query()->where('name', 'unknown')->exists())->toBeFalse();
});

it('blocks new tag names the tag model would refuse', function () {
    $import = valueMappedImport($this->user, $this->account, "Date,Description,Amount,Category,Tags\n15/03/2024,Hotel,-90.00,,x\n", ['categories' => [], 'tags' => [str_repeat('a', 51) => ['type' => 'new'], '__untagged__' => ['type' => 'new'], 'fine' => ['type' => 'new']]]);

    expect(app(BankImportValueMapping::class)->problems($import))->toHaveCount(2);
});

it('does not keep a transaction whose tag could not be created', function () {
    $import = valueMappedImport($this->user, $this->account, "Date,Description,Amount,Category,Tags\n15/03/2024,Hotel,-90.00,,__untagged__\n16/03/2024,Flight,-30.00,,ok\n", ['categories' => [], 'tags' => ['__untagged__' => ['type' => 'new'], 'ok' => ['type' => 'new']]]);

    $done = runValueImport($import);

    expect(Transaction::query()->where('account_id', $this->account->id)->pluck('description')->all())->toBe(['Flight'])
        ->and($done->imported_count)->toBe(1)
        ->and($done->skipped_count)->toBe(1);
});

it('shows an unreadable debit cell as money out so retyping it keeps the direction', function () {
    $mapping = [
        CsvColumnMapper::FIELD_DATE => 'Date',
        CsvColumnMapper::FIELD_DESCRIPTION => 'Description',
        CsvColumnMapper::FIELD_DEBIT => 'Debit',
        CsvColumnMapper::FIELD_CREDIT => 'Credit',
        ...new CsvFormat()->toMapping(),
    ];
    $path = 'bank-imports/debit.csv';
    Storage::disk('local')->put($path, "Date,Description,Debit,Credit\n15/03/2024,Shop,abc,\n16/03/2024,Refund,,0.00\n17/03/2024,Both,abc,0.00\n");
    $import = BankImport::factory()->for($this->user)->for($this->account)->create(['stored_path' => $path, 'column_mapping' => $mapping]);

    app(BankImportRowBuilder::class)->build($import);

    $rows = $import->rows()->orderBy('row_number')->get();
    $shown = $rows->pluck('raw_amount')->all();
    $edited = app(BankImportRowEditor::class)->update($rows[0], 'amount', '-12.50');

    expect($shown)->toBe(['-abc', '0.00', '-abc'])
        ->and($edited->amount)->toBe(-1250)
        ->and($edited->direction)->toBe(TransactionDirection::Debit);
});

it('shows an unreadable amount with the sign the file convention records', function () {
    $format = new CsvFormat(signConvention: CsvSignConvention::TypeColumn, typeInflowValue: 'credit');
    $mapping = [
        CsvColumnMapper::FIELD_DATE => 'Date',
        CsvColumnMapper::FIELD_DESCRIPTION => 'Description',
        CsvColumnMapper::FIELD_AMOUNT => 'Amount',
        CsvFormat::TYPE_COLUMN => 'Type',
        ...$format->toMapping(),
    ];
    $path = 'bank-imports/type.csv';
    Storage::disk('local')->put($path, "Date,Description,Amount,Type\n15/03/2024,Shop,abc,DEBIT\n16/03/2024,Pay,abc,CREDIT\n");
    $import = BankImport::factory()->for($this->user)->for($this->account)->create(['stored_path' => $path, 'column_mapping' => $mapping]);

    app(BankImportRowBuilder::class)->build($import);

    expect($import->rows()->orderBy('row_number')->pluck('raw_amount')->all())->toBe(['-abc', 'abc']);
});
