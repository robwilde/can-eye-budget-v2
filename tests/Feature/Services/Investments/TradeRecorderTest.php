<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\RecordTrade;
use App\Enums\TradeActivity;
use App\Enums\TransactionDirection;
use App\Exceptions\TradeRefusedException;
use App\Models\Account;
use App\Models\Security;
use App\Models\Trade;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Investments\TradeRecorder;
use App\Services\Reports\ReportAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 12:00:00'));
    $this->user = User::factory()->create(['base_currency' => 'AUD']);
    $this->account = Account::factory()->investment()->for($this->user)->create(['currency' => 'AUD']);
    $this->security = Security::factory()->create(['ticker' => 'vas']);
    $this->recorder = app(TradeRecorder::class);
});

function recordTrade(array $overrides = []): RecordTrade
{
    return new RecordTrade(...[
        'accountId' => test()->account->id,
        'activity' => TradeActivity::Buy,
        'date' => '2026-03-10',
        'securityId' => test()->security->id,
        'quantity' => '10',
        'price' => 2000,
        ...$overrides,
    ]);
}

function incomeOf(User $user): int
{
    return collect(app(ReportAggregator::class)->atoms($user, 'actual', null, null))->where('direction', 'credit')->sum('total');
}

it('records a buy with the amount as quantity times price plus the fee', function () {
    $trade = $this->recorder->record($this->user, recordTrade(['fee' => 995]));

    expect($trade)->toBeInstanceOf(Trade::class)
        ->and($trade->quantity)->toBe('10.00000000')
        ->and($trade->amount)->toBe(20995)
        ->and($trade->fee)->toBe(995)
        ->and($trade->name)->toBe('Buy 10 VAS')
        ->and($trade->currency)->toBe('AUD')
        ->and($trade->locked)->toBeFalse();
});

it('stores sells and sweep outs with a negative quantity and the proceeds net of the fee', function () {
    $sell = $this->recorder->record($this->user, recordTrade(['activity' => TradeActivity::Sell, 'quantity' => '4.5', 'price' => 3000, 'fee' => 500]));
    $sweep = $this->recorder->record($this->user, recordTrade(['activity' => TradeActivity::SweepOut, 'quantity' => '-2']));

    expect($sell->quantity)->toBe('-4.50000000')
        ->and($sell->amount)->toBe(-13000)
        ->and($sweep->quantity)->toBe('-2.00000000')
        ->and($sweep->amount)->toBe(-4000);
});

it('records sweep ins and reinvestments as acquisitions', function () {
    $in = $this->recorder->record($this->user, recordTrade(['activity' => TradeActivity::SweepIn]));
    $reinvest = $this->recorder->record($this->user, recordTrade(['activity' => TradeActivity::Reinvestment, 'quantity' => '0.5']));

    expect($in->quantity)->toBe('10.00000000')
        ->and($in->amount)->toBe(20000)
        ->and($reinvest->quantity)->toBe('0.50000000')
        ->and($reinvest->amount)->toBe(1000);
});

it('records a fractional quantity exactly', function () {
    $trade = $this->recorder->record($this->user, recordTrade(['quantity' => '0.12345678', 'price' => 10000]));

    expect($trade->fresh()->quantity)->toBe('0.12345678')
        ->and($trade->amount)->toBe(1235);
});

it('records a dividend as an inflow and needs a security', function () {
    $dividend = $this->recorder->record($this->user, recordTrade(['activity' => TradeActivity::Dividend, 'quantity' => null, 'price' => null, 'amount' => 4200]));

    expect($dividend->amount)->toBe(-4200)
        ->and($dividend->quantity)->toBe('0.00000000')
        ->and($dividend->security_id)->toBe($this->security->id);

    expect(fn () => $this->recorder->record($this->user, recordTrade(['activity' => TradeActivity::Dividend, 'securityId' => null, 'quantity' => null, 'price' => null, 'amount' => 4200])))
        ->toThrow(TradeRefusedException::class);
});

it('books interest and fees against a cash security of the trade currency', function () {
    $interest = $this->recorder->record($this->user, recordTrade(['activity' => TradeActivity::Interest, 'securityId' => null, 'quantity' => null, 'price' => null, 'amount' => 150]));
    $fee = $this->recorder->record($this->user, recordTrade(['activity' => TradeActivity::Fee, 'securityId' => null, 'quantity' => null, 'price' => null, 'amount' => 900]));
    $usd = $this->recorder->record($this->user, recordTrade(['activity' => TradeActivity::Interest, 'securityId' => null, 'quantity' => null, 'price' => null, 'amount' => 50, 'currency' => 'usd']));

    expect($interest->amount)->toBe(-150)
        ->and($fee->amount)->toBe(900)
        ->and($interest->security->ticker)->toBe('CASH-AUD')
        ->and($fee->security_id)->toBe($interest->security_id)
        ->and($usd->security->ticker)->toBe('CASH-USD')
        ->and($usd->currency)->toBe('USD')
        ->and(Security::query()->where('ticker', 'like', 'CASH-%')->count())->toBe(2);
});

it('records deposits with a counter-account as transfers under the counting rule', function () {
    $untracked = Account::factory()->untracked()->for($this->user)->create();
    $tracked = Account::factory()->for($this->user)->create();

    $fromUntracked = $this->recorder->record($this->user, recordTrade(['activity' => TradeActivity::Deposit, 'securityId' => null, 'quantity' => null, 'price' => null, 'amount' => 100000, 'counterAccountId' => $untracked->id]));

    expect($fromUntracked)->toBeInstanceOf(Transaction::class)
        ->and($fromUntracked->account_id)->toBe($untracked->id)
        ->and($fromUntracked->transfer_pair_id)->not->toBeNull()
        ->and(Transaction::query()->where('account_id', $this->account->id)->where('direction', TransactionDirection::Credit)->value('transfer_pair_id'))->toBe($fromUntracked->id)
        ->and(incomeOf($this->user))->toBe(100000);

    $this->recorder->record($this->user, recordTrade(['activity' => TradeActivity::Deposit, 'securityId' => null, 'quantity' => null, 'price' => null, 'amount' => 70000, 'counterAccountId' => $tracked->id]));

    expect(incomeOf($this->user))->toBe(100000);
});

it('records a withdrawal to a counter-account as a transfer out of the investment account', function () {
    $untracked = Account::factory()->untracked()->for($this->user)->create();

    $debit = $this->recorder->record($this->user, recordTrade(['activity' => TradeActivity::Withdrawal, 'securityId' => null, 'quantity' => null, 'price' => null, 'amount' => 25000, 'counterAccountId' => $untracked->id]));

    expect($debit->account_id)->toBe($this->account->id)
        ->and($debit->direction)->toBe(TransactionDirection::Debit)
        ->and($debit->transfer_pair_id)->not->toBeNull();
});

it('records a deposit without a counter-account as a plain transaction', function () {
    $deposit = $this->recorder->record($this->user, recordTrade(['activity' => TradeActivity::Deposit, 'securityId' => null, 'quantity' => null, 'price' => null, 'amount' => 5000]));
    $withdrawal = $this->recorder->record($this->user, recordTrade(['activity' => TradeActivity::Withdrawal, 'securityId' => null, 'quantity' => null, 'price' => null, 'amount' => 2000]));

    expect($deposit->direction)->toBe(TransactionDirection::Credit)
        ->and($deposit->transfer_pair_id)->toBeNull()
        ->and($withdrawal->direction)->toBe(TransactionDirection::Debit)
        ->and(Trade::query()->count())->toBe(0);
});

it('refuses trades on another user\'s account', function () {
    $stranger = Account::factory()->investment()->create();

    expect(fn () => $this->recorder->record($this->user, recordTrade(['accountId' => $stranger->id])))->toThrow(TradeRefusedException::class)
        ->and(Trade::query()->count())->toBe(0);
});

it('refuses a deposit whose counter-account is not the user\'s and writes nothing', function () {
    $stranger = Account::factory()->untracked()->create();

    expect(fn () => $this->recorder->record($this->user, recordTrade(['activity' => TradeActivity::Deposit, 'securityId' => null, 'quantity' => null, 'price' => null, 'amount' => 1000, 'counterAccountId' => $stranger->id])))
        ->toThrow(TradeRefusedException::class)
        ->and(Transaction::query()->count())->toBe(0);
});

it('refuses incomplete or impossible trades and writes nothing', function () {
    $bad = [
        ['quantity' => null],
        ['price' => null],
        ['quantity' => '0'],
        ['quantity' => 'abc'],
        ['price' => -1],
        ['fee' => -1],
        ['securityId' => null],
        ['securityId' => 999999],
    ];

    foreach ($bad as $overrides) {
        expect(fn () => $this->recorder->record($this->user, recordTrade($overrides)))->toThrow(TradeRefusedException::class);
    }

    expect(fn () => $this->recorder->record($this->user, recordTrade(['activity' => TradeActivity::Deposit, 'securityId' => null, 'quantity' => null, 'price' => null, 'amount' => 0])))->toThrow(TradeRefusedException::class)
        ->and(Trade::query()->count())->toBe(0);
});

it('records a sell beyond holdings rather than refusing it', function () {
    $sell = $this->recorder->record($this->user, recordTrade(['activity' => TradeActivity::Sell, 'quantity' => '99']));

    expect($sell->quantity)->toBe('-99.00000000');
});

it('derives the name and activity again and locks the trade when quantity or price change', function () {
    $trade = $this->recorder->record($this->user, recordTrade(['fee' => 500]));

    $edited = $this->recorder->update($this->user, $trade, quantity: '-3', price: 2500);

    expect($edited->activity)->toBe(TradeActivity::Sell)
        ->and($edited->quantity)->toBe('-3.00000000')
        ->and($edited->amount)->toBe(-7000)
        ->and($edited->name)->toBe('Sell 3 VAS')
        ->and($edited->locked)->toBeTrue();

    $back = $this->recorder->update($this->user, $edited, quantity: '2');

    expect($back->activity)->toBe(TradeActivity::Buy)
        ->and($back->name)->toBe('Buy 2 VAS')
        ->and($back->amount)->toBe(5500);
});

it('keeps the activity of a sweep or reinvestment when its quantity is edited', function () {
    $sweep = $this->recorder->record($this->user, recordTrade(['activity' => TradeActivity::SweepOut]));

    $edited = $this->recorder->update($this->user, $sweep, quantity: '5');

    expect($edited->activity)->toBe(TradeActivity::SweepOut)
        ->and($edited->quantity)->toBe('-5.00000000');
});

it('refuses to edit another user\'s trade or a trade that moves no shares', function () {
    $stranger = Account::factory()->investment()->create();
    $theirs = Trade::factory()->create(['account_id' => $stranger->id]);
    $dividend = $this->recorder->record($this->user, recordTrade(['activity' => TradeActivity::Dividend, 'quantity' => null, 'price' => null, 'amount' => 100]));

    expect(fn () => $this->recorder->update($this->user, $theirs, quantity: '1'))->toThrow(TradeRefusedException::class)
        ->and(fn () => $this->recorder->update($this->user, $dividend, quantity: '1'))->toThrow(TradeRefusedException::class)
        ->and($theirs->fresh()->quantity)->toBe('10.00000000');
});

it('refuses a zero quantity or negative price when editing', function () {
    $trade = $this->recorder->record($this->user, recordTrade());

    expect(fn () => $this->recorder->update($this->user, $trade, quantity: '0'))->toThrow(TradeRefusedException::class)
        ->and(fn () => $this->recorder->update($this->user, $trade, price: -5))->toThrow(TradeRefusedException::class)
        ->and($trade->fresh()->locked)->toBeFalse();
});

it('uses the trade currency when one is given and the account currency otherwise', function () {
    $usd = $this->recorder->record($this->user, recordTrade(['currency' => 'usd']));
    $default = $this->recorder->record($this->user, recordTrade());

    expect($usd->currency)->toBe('USD')
        ->and($default->currency)->toBe('AUD');
});

it('writes nothing when a cash trade is refused after its security was looked up', function () {
    expect(fn () => $this->recorder->record($this->user, recordTrade(['activity' => TradeActivity::Fee, 'securityId' => null, 'quantity' => null, 'price' => null, 'amount' => 0])))
        ->toThrow(TradeRefusedException::class)
        ->and(Security::query()->where('ticker', 'like', 'CASH-%')->count())->toBe(0)
        ->and(Trade::query()->count())->toBe(0);
});

it('locks the account rows in id order', function () {
    DB::enableQueryLog();
    $this->recorder->record($this->user, recordTrade());

    $locks = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'from "accounts"') && str_contains($sql, 'order by'));

    expect($locks)->not->toBeEmpty()
        ->and($locks->every(fn (string $sql): bool => str_contains($sql, 'order by "accounts"."id"') || str_contains($sql, 'order by "id"')))->toBeTrue();
});

it('uses the account currency for a blank currency and refuses one that is not a code', function () {
    $blank = $this->recorder->record($this->user, recordTrade(['currency' => '  ']));
    $padded = $this->recorder->record($this->user, recordTrade(['currency' => ' usd ']));

    expect($blank->currency)->toBe('AUD')
        ->and($padded->currency)->toBe('USD');

    foreach (['dollars', 'US', 'U$D', '12A'] as $bad) {
        expect(fn () => $this->recorder->record($this->user, recordTrade(['currency' => $bad])))->toThrow(TradeRefusedException::class);
    }

    expect(Security::query()->where('currency', 'dollars')->count())->toBe(0);
});

it('refuses a deposit whose counter-account uses another currency', function () {
    $usd = Account::factory()->untracked()->for($this->user)->create(['currency' => 'USD']);

    expect(fn () => $this->recorder->record($this->user, recordTrade(['activity' => TradeActivity::Deposit, 'securityId' => null, 'quantity' => null, 'price' => null, 'amount' => 1000, 'counterAccountId' => $usd->id])))
        ->toThrow(TradeRefusedException::class)
        ->and(Transaction::query()->count())->toBe(0);
});

it('refuses a deposit or withdrawal in a currency other than the account\'s', function () {
    expect(fn () => $this->recorder->record($this->user, recordTrade(['activity' => TradeActivity::Deposit, 'securityId' => null, 'quantity' => null, 'price' => null, 'amount' => 1000, 'currency' => 'USD'])))
        ->toThrow(TradeRefusedException::class);

    $ok = $this->recorder->record($this->user, recordTrade(['activity' => TradeActivity::Deposit, 'securityId' => null, 'quantity' => null, 'price' => null, 'amount' => 1000, 'currency' => 'aud']));

    expect($ok)->toBeInstanceOf(Transaction::class);
});

it('does not let a buy keep a negative quantity', function () {
    $trade = $this->recorder->record($this->user, recordTrade(['quantity' => '-5']));

    expect($trade->quantity)->toBe('5.00000000')
        ->and($trade->amount)->toBe(10000);
});
