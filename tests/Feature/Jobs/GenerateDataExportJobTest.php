<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\DataExportStatus;
use App\Jobs\GenerateDataExportJob;
use App\Models\Account;
use App\Models\DataExport;
use App\Models\Document;
use App\Models\Transaction;
use App\Models\TransactionAttachment;
use App\Models\TransactionSplit;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function runExport(DataExport $export): DataExport
{
    app()->call([new GenerateDataExportJob($export), 'handle']);

    return $export->fresh();
}

/** @return array<string, string> */
function exportEntries(DataExport $export): array
{
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($export->stored_path));
    $entries = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entries[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }

    $zip->close();

    return $entries;
}

it('writes the export zip with csvs, ndjson and a manifest', function () {
    $transaction = Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id]);
    Storage::disk('local')->put('attachments/receipt.pdf', 'attachment bytes');
    TransactionAttachment::factory()->create(['transaction_id' => $transaction->id, 'disk' => 'local', 'path' => 'attachments/receipt.pdf', 'original_name' => 'receipt.pdf', 'size_bytes' => 16]);
    Document::factory()->for($this->user)->create(['filename' => 'notes.docx']);

    $export = runExport(DataExport::factory()->for($this->user)->create());
    $entries = exportEntries($export);

    expect($export->status)->toBe(DataExportStatus::Completed)
        ->and(array_keys($entries))->toEqualCanonicalizing(['accounts.csv', 'transactions.csv', 'categories.csv', 'merchants.csv', 'payees.csv', 'rules.csv', 'all.ndjson', 'manifest.json'])
        ->and($entries['all.ndjson'])->toContain('"type":"transactions"')
        ->and($entries['manifest.json'])->toContain('receipt.pdf')->toContain(hash('sha256', 'attachment bytes'))->toContain('notes.docx')
        ->and(implode('', $entries))->not->toContain('attachment bytes');
});

it('exports split parts instead of the parent', function () {
    $parent = Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'amount' => -10000, 'description' => 'Split purchase']);
    TransactionSplit::query()->create(['transaction_id' => $parent->id, 'amount' => -6000, 'position' => 1]);
    TransactionSplit::query()->create(['transaction_id' => $parent->id, 'amount' => -4000, 'position' => 2]);

    $csv = exportEntries(runExport(DataExport::factory()->for($this->user)->create()))['transactions.csv'];

    expect($csv)->toContain('-60.00')->toContain('-40.00')->not->toContain('-100.00');
});

it('exports only the requesting user\'s data', function () {
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'description' => 'Mine']);
    $other = User::factory()->create();
    Transaction::factory()->for($other)->create(['account_id' => Account::factory()->for($other)->create()->id, 'description' => 'Theirs']);

    $entries = exportEntries(runExport(DataExport::factory()->for($this->user)->create()));

    expect(implode('', $entries))->toContain('Mine')->not->toContain('Theirs');
});

it('never restarts a completed or failed export', function () {
    $export = runExport(DataExport::factory()->for($this->user)->create());
    $path = $export->stored_path;
    $size = $export->byte_size;
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'description' => 'Added later']);

    $again = runExport($export);

    expect($again->status)->toBe(DataExportStatus::Completed)
        ->and($again->byte_size)->toBe($size)
        ->and(Storage::disk('local')->exists($path))->toBeTrue();

    $failed = DataExport::factory()->for($this->user)->create(['status' => DataExportStatus::Failed]);
    expect(runExport($failed)->status)->toBe(DataExportStatus::Failed);
});

it('fails exports stuck for over two hours', function () {
    $stuck = DataExport::factory()->for($this->user)->processingSince(now()->subHours(3))->create();
    $fresh = DataExport::factory()->for($this->user)->processingSince(now()->subHour())->create();

    expect(DataExport::failStuck())->toBe(1)
        ->and($stuck->fresh()->status)->toBe(DataExportStatus::Failed)
        ->and($fresh->fresh()->status)->toBe(DataExportStatus::Processing);
});

it('writes amounts as decimal strings from integer cents', function () {
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'amount' => -1205]);

    expect(exportEntries(runExport(DataExport::factory()->for($this->user)->create()))['transactions.csv'])->toContain('-12.05');
});

it('marks the export failed when the archive cannot be written', function () {
    $export = DataExport::factory()->for($this->user)->create();
    Storage::shouldReceive('disk')->with('local')->andThrow(new RuntimeException('disk full'));

    $done = runExport($export);

    expect($done->status)->toBe(DataExportStatus::Failed)->and($done->failure_message)->not->toContain('disk full');
});

it('escapes text cells that could run as a formula but keeps negative amounts', function () {
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'amount' => -1205, 'description' => '=HYPERLINK("http://evil.example","x")']);

    $csv = exportEntries(runExport(DataExport::factory()->for($this->user)->create()))['transactions.csv'];

    expect($csv)->not->toContain(',"=HYPERLINK')->toContain("'=HYPERLINK")->toContain('-12.05');
});

it('exports the currency, notes, payees and rule definitions', function () {
    Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'currency' => 'USD', 'notes' => 'my note']);
    App\Models\Payee::factory()->for($this->user)->create(['merchant_name' => 'PAYEE-ZZZ']);
    App\Models\UserRule::factory()->for($this->user)->create(['triggers' => [['value' => 'NETFLIX']]]);

    $entries = exportEntries(runExport(DataExport::factory()->for($this->user)->create()));

    expect($entries['transactions.csv'])->toContain('USD')->toContain('my note')
        ->and($entries['payees.csv'])->toContain('PAYEE-ZZZ')
        ->and($entries['rules.csv'])->toContain('NETFLIX');
});

it('never exports credentials, tokens or vector store ids', function () {
    $user = User::factory()->create(['vector_store_id' => 'vs_secret_id']);
    App\Models\GmailCredential::factory()->for($user)->create(['app_password' => 'gmail-app-secret']);
    App\Models\RedbarkFeed::factory()->for($user)->create(['api_key' => 'rbk_live_secret_key']);

    $all = implode('', exportEntries(runExport(DataExport::factory()->for($user)->create())));

    expect($all)->not->toContain('vs_secret_id')->not->toContain('gmail-app-secret')->not->toContain('rbk_live_secret_key')->not->toContain($user->password)->not->toContain((string) $user->remember_token);
});

it('marks the export failed when the archive cannot be stored', function () {
    $export = DataExport::factory()->for($this->user)->create();
    Storage::shouldReceive('disk')->with('local')->andReturn(new class
    {
        public function writeStream(): bool
        {
            return false;
        }
    });

    expect(runExport($export)->status)->toBe(DataExportStatus::Failed);
});

it('removes the stored archive when the export is deleted', function () {
    $export = runExport(DataExport::factory()->for($this->user)->create());
    $path = $export->stored_path;

    $export->delete();

    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

it('removes archives and documents when a user is deleted', function () {
    $export = runExport(DataExport::factory()->for($this->user)->create());
    Storage::disk('local')->put('documents/d.docx', 'x');
    Document::factory()->for($this->user)->create(['stored_path' => 'documents/d.docx']);

    $this->user->delete();

    expect(Storage::disk('local')->exists($export->stored_path))->toBeFalse()
        ->and(Storage::disk('local')->exists('documents/d.docx'))->toBeFalse();
});
