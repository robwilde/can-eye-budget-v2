<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\RecordTrade;
use App\Enums\TradeActivity;
use App\Models\Account;
use App\Models\AccountDailyBalance;
use App\Models\Security;
use App\Models\SecurityPrice;
use App\Models\Statement;
use App\Models\User;
use App\Services\AccountValuationService;
use App\Services\Investments\TradeRecorder;
use App\Services\Statement\StatementBalanceChecker;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo('2026-03-05 12:00:00');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->investment()->for($this->user)->create(['balance' => 103000, 'currency' => 'AUD']);
    $security = Security::factory()->create(['currency' => 'AUD', 'ticker' => 'VAS']);
    app(AccountValuationService::class)->setOpeningBalance($this->account, 100000, CarbonImmutable::parse('2026-03-01'));
    app(AccountValuationService::class)->reconcile($this->account->fresh(), 103000, CarbonImmutable::parse('2026-03-05'));

    foreach (['2026-03-01' => 1500, '2026-03-03' => 1800] as $date => $price) {
        SecurityPrice::factory()->create(['security_id' => $security->id, 'currency' => 'AUD', 'date' => $date, 'price' => $price]);
    }

    app(TradeRecorder::class)->record($this->user, new RecordTrade(accountId: $this->account->id, activity: TradeActivity::Buy, date: '2026-03-01', securityId: $security->id, quantity: '10', price: 1500, currency: 'AUD'));
});

function totalPeriodStatement(Account $account, ?int $opening, ?int $closing): Statement
{
    return Statement::factory()->for($account->user)->create([
        'account_id' => $account->id,
        'period_start' => '2026-03-02',
        'period_end' => '2026-03-05',
        'opening_balance' => $opening,
        'closing_balance' => $closing,
        'currency' => 'AUD',
    ]);
}

it('passes an investment statement that reports the total portfolio value', function () {
    $first = $this->account->dailyBalances()->whereDate('balance_date', '2026-03-02')->firstOrFail();
    $last = $this->account->dailyBalances()->whereDate('balance_date', '2026-03-05')->firstOrFail();

    $check = app(StatementBalanceChecker::class)->check(totalPeriodStatement($this->account, 100000, 103000));

    expect($first->start_balance)->toBe(85000)
        ->and($last->end_balance)->toBe(85000)
        ->and($check->checked)->toBeTrue()
        ->and($check->failures)->toBe([]);
});

it('fails an investment statement that reports only the cash balance', function () {
    $check = app(StatementBalanceChecker::class)->check(totalPeriodStatement($this->account, 85000, 85000));

    expect($check->failures)->toBe(['opening', 'closing', 'movement']);
});

it('measures the movement of an investment account on its total', function () {
    $check = app(StatementBalanceChecker::class)->check(totalPeriodStatement($this->account, 85000, 88000));

    expect($check->failures)->toBe(['opening', 'closing']);
});

it('checks an account with no securities against its stored balances', function () {
    $bank = Account::factory()->for($this->user)->create(['currency' => 'AUD']);
    AccountDailyBalance::factory()->create(['account_id' => $bank->id, 'balance_date' => '2026-03-02', 'start_balance' => 50000, 'end_balance' => 52000, 'currency' => 'AUD']);
    AccountDailyBalance::factory()->create(['account_id' => $bank->id, 'balance_date' => '2026-03-05', 'start_balance' => 58000, 'end_balance' => 60000, 'currency' => 'AUD']);
    $checker = app(StatementBalanceChecker::class);

    $matching = Statement::factory()->for($this->user)->create(['account_id' => $bank->id, 'period_start' => '2026-03-02', 'period_end' => '2026-03-05', 'opening_balance' => 50000, 'closing_balance' => 60000, 'currency' => 'AUD']);
    $differing = Statement::factory()->for($this->user)->create(['account_id' => $bank->id, 'period_start' => '2026-03-02', 'period_end' => '2026-03-05', 'opening_balance' => 50000, 'closing_balance' => 61000, 'currency' => 'AUD']);

    expect($checker->check($matching)->checked)->toBeTrue()
        ->and($checker->check($matching)->failures)->toBe([])
        ->and($checker->check($differing)->failures)->toBe(['closing', 'movement']);
});
