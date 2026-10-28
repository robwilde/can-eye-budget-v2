<?php

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\AccountValuation;
use App\Models\RedbarkAccount;
use App\Models\Transaction;
use App\Services\Projection\AccountBalanceHistoryBuilder;

beforeEach(function () {
    $this->travelTo('2026-05-12');
    $this->account = Account::factory()->create(['import_source' => ImportSource::Redbark, 'balance' => 0]);
    AccountValuation::factory()->for($this->account)->opening()->create(['valued_on' => '2026-04-01', 'balance' => 100000]);
    RedbarkAccount::factory()->create(['account_id' => $this->account->id, 'raw_transactions_payload' => []]);
});

function expiryHold(Account $account, TransactionSource $source, int $cents): Transaction
{
    return Transaction::factory()->create([
        'account_id' => $account->id,
        'user_id' => $account->user_id,
        'source' => $source,
        'status' => TransactionStatus::Pending,
        'direction' => TransactionDirection::Debit,
        'amount' => $cents,
        'redbark_id' => 'rb_'.$cents,
        'post_date' => '2026-04-20',
    ]);
}

function expiryEnd(Account $account): int
{
    return $account->dailyBalances()->whereDate('balance_date', '2026-05-12')->sole()->end_balance;
}

test('rebuilds history when an orphaned hold is expired', function () {
    expiryHold($this->account, TransactionSource::Redbark, -3000);
    app(AccountBalanceHistoryBuilder::class)->rebuild($this->account);
    $this->account->dailyBalances()->whereDate('balance_date', '>=', '2026-04-20')->update(['end_balance' => 777]);

    $this->artisan('app:expire-redbark-holds')->assertSuccessful();

    expect(expiryEnd($this->account))->toBe(100000);
});

test('rebuilds history when an adopted row is reverted to posted', function () {
    expiryHold($this->account, TransactionSource::Csv, -3000);
    app(AccountBalanceHistoryBuilder::class)->rebuild($this->account);

    expect(expiryEnd($this->account))->toBe(100000);

    $this->artisan('app:expire-redbark-holds')->assertSuccessful();

    expect(expiryEnd($this->account))->toBe(97000);
});

test('leaves history untouched on a dry run', function () {
    expiryHold($this->account, TransactionSource::Csv, -3000);
    app(AccountBalanceHistoryBuilder::class)->rebuild($this->account);

    $this->artisan('app:expire-redbark-holds --dry-run')->assertSuccessful();

    expect(expiryEnd($this->account))->toBe(100000);
});
