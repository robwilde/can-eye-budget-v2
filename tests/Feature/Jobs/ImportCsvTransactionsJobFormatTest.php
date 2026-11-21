<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\CsvFormat;
use App\Jobs\ImportCsvTransactionsJob;
use App\Models\Account;
use App\Models\BankImport;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CsvImport\CsvColumnMapper;
use App\Services\CsvImport\CsvParserService;
use App\Services\TransactionIngestor;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('imports identical rows within one file but dedupes a repeated file', function () {
    Storage::fake('local');
    Queue::fake();
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->csvImport()->create();
    $content = "Date,Description,Amount\n15/03/2024,COFFEE,-4.50\n15/03/2024,COFFEE,-4.50\n16/03/2024,LUNCH,-9.00\n";

    $run = function () use ($user, $account, $content): BankImport {
        $path = 'bank-imports/'.bin2hex(random_bytes(6)).'.csv';
        Storage::disk('local')->put($path, $content);
        $import = BankImport::factory()->for($user)->for($account)->create([
            'stored_path' => $path,
            'column_mapping' => [
                CsvColumnMapper::FIELD_DATE => 'Date',
                CsvColumnMapper::FIELD_DESCRIPTION => 'Description',
                CsvColumnMapper::FIELD_AMOUNT => 'Amount',
                ...new CsvFormat()->toMapping(),
            ],
        ]);

        new ImportCsvTransactionsJob($import)->handle(new CsvParserService, app(TransactionIngestor::class));

        return $import->fresh();
    };

    $first = $run();
    $second = $run();

    expect($first->imported_count)->toBe(3)
        ->and(Transaction::query()->where('account_id', $account->id)->where('description', 'COFFEE')->count())->toBe(2)
        ->and($second->imported_count)->toBe(0)
        ->and(Transaction::query()->where('account_id', $account->id)->count())->toBe(3);
});
