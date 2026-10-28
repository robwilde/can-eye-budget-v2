<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Jobs\ImportCsvTransactionsJob;
use App\Jobs\RunTransactionAnalysisJob;
use App\Models\Account;
use App\Models\AccountValuation;
use App\Models\BankImport;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CsvImport\CsvColumnMapper;
use App\Services\CsvImport\CsvParserService;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use App\Services\TransactionIngestor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake([RunTransactionAnalysisJob::class]);
    $this->travelTo('2026-08-27 09:00:00');
});

function historyCsvImport(): BankImport
{
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->csvImport()->create();
    AccountValuation::factory()->for($account)->opening()->create(['valued_on' => '2026-08-01', 'balance' => 100000]);
    app(AccountBalanceHistoryBuilder::class)->rebuild($account);

    Storage::disk('local')->put('bank-imports/history.csv', implode("\n", [
        'Date,Description,Amount',
        '13/08/2026,SHOP A,-42.50',
        '15/08/2026,SHOP B,-10.00',
        '20/08/2026,PAY,100.00',
    ]));

    return BankImport::factory()->for($user)->for($account)->create([
        'stored_path' => 'bank-imports/history.csv',
        'column_mapping' => [
            CsvColumnMapper::FIELD_DATE => 'Date',
            CsvColumnMapper::FIELD_DESCRIPTION => 'Description',
            CsvColumnMapper::FIELD_AMOUNT => 'Amount',
        ],
    ]);
}

function historyCsvRun(BankImport $bankImport): void
{
    new ImportCsvTransactionsJob($bankImport)->handle(new CsvParserService, app(TransactionIngestor::class));
}

function historyCsvEnd(Account $account, string $date): int
{
    return $account->dailyBalances()->whereDate('balance_date', $date)->sole()->end_balance;
}

test('rebuilds the account history once for a whole import', function () {
    $bankImport = historyCsvImport();
    $rebuilds = 0;
    DB::listen(function ($query) use (&$rebuilds) {
        if (preg_match('/^delete from [`"]?account_daily_balances/i', $query->sql)) {
            $rebuilds++;
        }
    });

    historyCsvRun($bankImport);

    expect(Transaction::count())->toBe(3)
        ->and($rebuilds)->toBe(1)
        ->and(historyCsvEnd($bankImport->account, '2026-08-12'))->toBe(100000)
        ->and(historyCsvEnd($bankImport->account, '2026-08-13'))->toBe(95750)
        ->and(historyCsvEnd($bankImport->account, '2026-08-27'))->toBe(104750);
});

test('rebuilds the account history when a re-import restores a deleted row', function () {
    $bankImport = historyCsvImport();
    historyCsvRun($bankImport);

    Transaction::query()->where('description', 'SHOP A')->sole()->delete();
    app(AccountBalanceHistoryBuilder::class)->rebuild($bankImport->account);

    expect(historyCsvEnd($bankImport->account, '2026-08-27'))->toBe(109000);

    historyCsvRun($bankImport);

    expect(historyCsvEnd($bankImport->account, '2026-08-12'))->toBe(100000)
        ->and(historyCsvEnd($bankImport->account, '2026-08-13'))->toBe(95750)
        ->and(historyCsvEnd($bankImport->account, '2026-08-27'))->toBe(104750);
});
