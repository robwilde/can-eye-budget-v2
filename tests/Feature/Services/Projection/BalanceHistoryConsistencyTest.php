<?php

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountValuationService;
use App\Services\TransactionIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\ExpectationFailedException;

beforeEach(function () {
    $this->travelTo('2026-05-12');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create(['balance' => 0, 'balance_source' => ImportSource::Manual]);
    app(AccountValuationService::class)->setOpeningBalance($this->account, 100000, CarbonImmutable::parse('2026-05-01'));
});

function consistencyIngest(Account $account, int $cents, string $date, array $overrides = []): Transaction
{
    return app(TransactionIngestor::class)->ingest(new Transaction($overrides + [
        'user_id' => $account->user_id,
        'account_id' => $account->id,
        'amount' => abs($cents),
        'direction' => $cents < 0 ? TransactionDirection::Debit : TransactionDirection::Credit,
        'description' => 'Bunnings',
        'post_date' => $date,
        'status' => TransactionStatus::Posted,
        'source' => TransactionSource::Manual,
    ]));
}

function consistencyStoredRows(Account $account): array
{
    return DB::table('account_daily_balances')->where('account_id', $account->id)->orderBy('id')->get()->all();
}

it('passes when stored balance history matches a rebuild', function () {
    consistencyIngest($this->account, -2000, '2026-05-05');
    consistencyIngest($this->account, 5000, '2026-05-09');

    expectBalanceHistoryConsistent($this->account);
});

it('fails and names the first differing date when a rebuild was skipped', function () {
    consistencyIngest($this->account, -2000, '2026-05-05');

    DB::table('transactions')->insert([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'amount' => 3000,
        'direction' => TransactionDirection::Debit->value,
        'description' => 'Inserted without a rebuild',
        'post_date' => '2026-05-08',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => expectBalanceHistoryConsistent($this->account))
        ->toThrow(ExpectationFailedException::class, "Account {$this->account->id} balance history differs from a rebuild on 2026-05-08")
        ->and(fn () => expectBalanceHistoryConsistent($this->account))
        ->toThrow(ExpectationFailedException::class, 'end_balance stored 98000, rebuilt 95000');
});

it('keeps excluded transactions in the account balance', function () {
    consistencyIngest($this->account, -5000, '2026-05-05', ['excluded' => true]);

    $row = $this->account->dailyBalances()->whereDate('balance_date', '2026-05-05')->sole();

    expect($row->end_balance)->toBe(95000)
        ->and($row->outflows)->toBe(5000);

    expectBalanceHistoryConsistent($this->account);

    expect($this->account->dailyBalances()->whereDate('balance_date', '2026-05-12')->sole()->end_balance)->toBe(95000);
});

it('leaves stored balance history untouched', function () {
    consistencyIngest($this->account, -2000, '2026-05-05');
    DB::table('account_daily_balances')->where('account_id', $this->account->id)->whereDate('balance_date', '>=', '2026-05-06')->update(['end_balance' => 777]);
    $before = consistencyStoredRows($this->account);

    expect(fn () => expectBalanceHistoryConsistent($this->account))->toThrow(ExpectationFailedException::class);

    expect(consistencyStoredRows($this->account))->toEqual($before);
});
