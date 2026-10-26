<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RefreshTrigger;
use App\Enums\TransactionDirection;
use App\Jobs\ImportCsvTransactionsJob;
use App\Jobs\RunTransactionAnalysisJob;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\Account;
use App\Models\BankImport;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CsvImport\CsvColumnMapper;
use App\Services\CsvImport\CsvParserService;
use App\Services\RedbarkClientFactory;
use App\Services\RedbarkTransactionMatcher;
use App\Services\TransactionIngestor;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('gives a row ingested on a euro account the euro currency', function () {
    Queue::fake();
    $account = Account::factory()->create(['currency' => 'EUR']);

    $transaction = app(TransactionIngestor::class)->ingest(new Transaction([
        'user_id' => $account->user_id,
        'account_id' => $account->id,
        'amount' => 1200,
        'direction' => TransactionDirection::Debit,
        'description' => 'CAFE',
        'post_date' => '2026-10-10',
    ]));

    expect($transaction->fresh()->currency)->toBe('EUR');
});

it('gives csv imported rows on a euro account the euro currency', function () {
    Queue::fake([RunTransactionAnalysisJob::class]);
    Storage::fake('local');
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->csvImport()->create(['currency' => 'EUR']);
    Storage::disk('local')->put('bank-imports/euro.csv', file_get_contents(base_path('tests/Fixtures/StatementCsv-Westpac.csv')));
    $bankImport = BankImport::factory()->for($user)->for($account)->create([
        'original_filename' => 'StatementCsv-Westpac.csv',
        'stored_path' => 'bank-imports/euro.csv',
        'column_mapping' => [
            CsvColumnMapper::FIELD_DATE => 'Entered Date',
            CsvColumnMapper::FIELD_DESCRIPTION => 'Transaction Description',
            CsvColumnMapper::FIELD_AMOUNT => 'Amount',
            CsvColumnMapper::FIELD_BALANCE => 'Balance',
        ],
    ]);

    new ImportCsvTransactionsJob($bankImport)->handle(new CsvParserService, app(TransactionIngestor::class));

    expect(Transaction::query()->where('account_id', $account->id)->count())->toBeGreaterThan(10)
        ->and(Transaction::query()->where('account_id', $account->id)->where('currency', '!=', 'EUR')->count())->toBe(0);
});

it('gives a redbark synced row on a euro account the euro currency', function () {
    Queue::fake();
    [$feed, , $account] = linkedRedbarkFeed(redbarkAccountOverrides: ['currency' => 'EUR']);
    $account->update(['currency' => 'EUR']);

    fakeRedbark(
        accounts: [redbarkUpstreamAccount(['currency' => 'EUR'])],
        transactions: [[
            'id' => 'rb_txn_1',
            'accountId' => 'rb_acc_1',
            'status' => 'posted',
            'date' => '2026-10-10',
            'postDate' => '2026-10-10',
            'description' => 'BOULANGERIE PARIS',
            'amount' => '-4.50',
            'direction' => 'debit',
            'merchantName' => 'Boulangerie',
        ]],
    );

    new SyncRedbarkFeedJob($feed, RefreshTrigger::Manual)->handle(
        app(RedbarkClientFactory::class),
        app(TransactionIngestor::class),
        app(RedbarkTransactionMatcher::class),
    );

    $transaction = Transaction::query()->where('account_id', $account->id)->sole();

    expect($transaction->redbark_id)->toBe('rb_txn_1')
        ->and($transaction->currency)->toBe('EUR');
});
