<?php

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Livewire\Dashboard;
use App\Livewire\Dashboard\NetWorth;
use App\Models\Account;
use App\Models\AccountDailyBalance;
use App\Models\AccountValuation;
use App\Models\ExchangeRate;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\NetWorthService;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use App\Services\Reports\ReportAggregator;
use App\Support\Transactions\TransactionTotals;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo('2026-10-15');
    config(['budget.multi_currency' => true, 'services.exchange_rates.enabled' => false]);
    $this->user = User::factory()->create(['base_currency' => 'USD']);
});

function mcRate(string $date, float $rate, string $from = 'EUR', string $to = 'USD'): void
{
    ExchangeRate::factory()->create(['from_currency' => $from, 'to_currency' => $to, 'rate_date' => $date, 'rate' => $rate]);
}

function mcAccount(User $user, string $currency, array $overrides = []): Account
{
    return Account::factory()->for($user)->create(array_merge(['currency' => $currency], $overrides));
}

function mcRow(User $user, Account $account, string $date, int $amount, string $currency, TransactionDirection $direction = TransactionDirection::Debit): Transaction
{
    return Transaction::factory()->for($user)->for($account)->create([
        'amount' => $amount,
        'direction' => $direction,
        'currency' => $currency,
        'post_date' => $date,
        'transfer_pair_id' => null,
    ]);
}

function mcTransfer(User $user, Account $from, Account $to, string $date, int $amount): void
{
    $debit = mcRow($user, $from, $date, $amount, $from->currency);
    $credit = mcRow($user, $to, $date, $amount, $to->currency, TransactionDirection::Credit);
    $credit->update(['transfer_pair_id' => $debit->id]);
    $debit->update(['transfer_pair_id' => $credit->id]);
}

function mcTotals(User $user): array
{
    return TransactionTotals::summarise(Transaction::query()->where('user_id', $user->id)->current(), $user->id);
}

function mcReportTotal(User $user): int
{
    return array_sum(array_column(app(ReportAggregator::class)->atoms($user, 'actual', null, null), 'total'));
}

it('converts account balances at todays rate on the balance sheet', function () {
    mcRate('2026-10-15', 1.10);
    mcAccount($this->user, 'EUR', ['balance' => 100000]);

    $sheet = app(NetWorthService::class)->balanceSheet($this->user);

    expect($sheet->assetTotal)->toBe(110000)
        ->and($sheet->assets[0]->total)->toBe(110000)
        ->and(app(NetWorthService::class)->current($this->user))->toBe(110000);
});

it('counts an amount at rate one when no rate is available', function () {
    $account = mcAccount($this->user, 'EUR');
    mcRow($this->user, $account, '2026-10-10', 10000, 'EUR');

    expect(mcTotals($this->user)['expenses'])->toBe(10000)
        ->and(mcReportTotal($this->user))->toBe(10000);
});

it('converts transaction totals at each entry date', function () {
    mcRate('2026-10-10', 1.10);
    mcRate('2026-10-12', 1.20);
    $euro = mcAccount($this->user, 'EUR');
    $dollar = mcAccount($this->user, 'USD');
    mcRow($this->user, $euro, '2026-10-10', 10000, 'EUR');
    mcRow($this->user, $euro, '2026-10-12', 10000, 'EUR');
    mcRow($this->user, $dollar, '2026-10-12', 5000, 'USD');

    expect(mcTotals($this->user)['expenses'])->toBe(11000 + 12000 + 5000)
        ->and(mcReportTotal($this->user))->toBe(11000 + 12000 + 5000);
});

it('keeps totals unconverted when multi currency is off', function () {
    config(['budget.multi_currency' => false]);
    mcRate('2026-10-15', 1.10);
    mcRate('2026-10-10', 1.10);
    mcAccount($this->user, 'EUR', ['balance' => 100000]);
    $dollar = mcAccount($this->user, 'USD', ['balance' => 50000]);
    mcRow($this->user, $dollar, '2026-10-10', 7000, 'EUR');

    expect($this->user->totalAvailable())->toBe(150000)
        ->and(app(NetWorthService::class)->current($this->user))->toBe(150000)
        ->and(mcTotals($this->user)['expenses'])->toBe(7000)
        ->and(mcReportTotal($this->user))->toBe(7000);
});

it('fully rebuilds history for a multi-currency account', function () {
    $account = mcAccount($this->user, 'USD');
    AccountValuation::factory()->for($account)->opening()->create(['valued_on' => '2026-10-01', 'balance' => 100000]);
    mcRow($this->user, $account, '2026-10-05', 2000, 'USD');
    mcRow($this->user, $account, '2026-10-06', 3000, 'EUR');
    $builder = app(AccountBalanceHistoryBuilder::class);
    $builder->rebuild($account);
    $account->dailyBalances()->whereDate('balance_date', '2026-10-02')->update(['end_balance' => 1]);

    $builder->rebuild($account, CarbonImmutable::parse('2026-10-10'));

    expect($account->dailyBalances()->whereDate('balance_date', '2026-10-02')->sole()->end_balance)->toBe(100000);
});

it('keeps the transfer counting rule when converting totals', function () {
    mcRate('2026-10-10', 1.10);
    $tracked = mcAccount($this->user, 'EUR');
    $otherTracked = mcAccount($this->user, 'EUR');
    $untracked = Account::factory()->untracked()->for($this->user)->create(['currency' => 'EUR']);
    mcTransfer($this->user, $tracked, $untracked, '2026-10-10', 10000);
    mcTransfer($this->user, $tracked, $otherTracked, '2026-10-10', 10000);

    $totals = mcTotals($this->user);

    expect($totals['expenses'])->toBe(11000)
        ->and($totals['income'])->toBe(0)
        ->and(mcReportTotal($this->user))->toBe(11000);
});

it('shows each balance sheet row in the base currency', function () {
    mcRate('2026-10-15', 1.10);
    mcAccount($this->user, 'EUR', ['balance' => 100000]);

    Livewire::actingAs($this->user)
        ->test(NetWorth::class)
        ->assertSeeHtml('data-test="net-worth-figure">$1,100.00</div>')
        ->assertSeeHtmlInOrder(['data-test="balance-sheet-account"', '$1,100.00']);
});

it('converts each net worth day at that days rate', function () {
    mcRate('2026-10-10', 1.10);
    mcRate('2026-10-12', 1.20);
    $account = mcAccount($this->user, 'EUR', ['balance' => 100000]);

    foreach (['2026-10-10', '2026-10-11', '2026-10-12'] as $day) {
        AccountDailyBalance::factory()->for($account)->create(['balance_date' => $day, 'start_balance' => 100000, 'end_balance' => 100000]);
    }

    $series = app(NetWorthService::class)->series($this->user, CarbonImmutable::parse('2026-10-10'), CarbonImmutable::parse('2026-10-12'));

    expect($series)->toBe(['2026-10-10' => 110000, '2026-10-11' => 110000, '2026-10-12' => 120000]);
});

it('converts planned report amounts at each occurrence date', function () {
    mcRate('2026-10-14', 1.10);
    mcRate('2026-10-15', 1.30);
    $account = mcAccount($this->user, 'EUR');
    PlannedTransaction::factory()->noRepeat()->for($this->user)->for($account)->create([
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-10-14',
    ]);

    $atoms = app(ReportAggregator::class)->atoms($this->user, 'plan', CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));

    expect(array_sum(array_column($atoms, 'total')))->toBe(11000);
});

it('converts a planned transfer from the source account currency', function () {
    mcRate('2026-10-14', 1.10);
    $source = Account::factory()->untracked()->for($this->user)->create(['currency' => 'EUR']);
    $destination = mcAccount($this->user, 'USD');
    PlannedTransaction::factory()->noRepeat()->for($this->user)->for($source)->create([
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'transfer_to_account_id' => $destination->id,
        'start_date' => '2026-10-14',
    ]);

    $atoms = app(ReportAggregator::class)->atoms($this->user, 'plan', CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));

    expect(array_sum(array_column($atoms, 'total')))->toBe(11000);
});

it('subtracts needed from available in the base currency', function () {
    mcRate('2026-10-15', 1.10);
    $user = User::factory()->withPayCycle()->create(['base_currency' => 'USD', 'next_pay_date' => now()->addDays(14)->startOfDay()]);
    $account = mcAccount($user, 'EUR', ['balance' => 10000]);
    PlannedTransaction::factory()->noRepeat()->for($user)->for($account)->create([
        'amount' => 10500,
        'direction' => TransactionDirection::Debit,
        'start_date' => now()->addDays(2)->startOfDay(),
    ]);

    expect($user->totalAvailable())->toBe(11000)
        ->and($user->totalNeededUntilPayday())->toBe(11550)
        ->and($user->bufferUntilNextPay($user->totalAvailable()))->toBe(-550);
});

it('names the base currency on the net worth card only when converting', function () {
    Livewire::actingAs($this->user)
        ->test(NetWorth::class)
        ->assertSeeHtml('data-test="net-worth-base-currency"')
        ->assertSee('All amounts converted to USD');

    config(['budget.multi_currency' => false]);

    Livewire::actingAs($this->user)
        ->test(NetWorth::class)
        ->assertDontSeeHtml('data-test="net-worth-base-currency"');
});

it('names the base currency under the dashboard cards only when converting', function () {
    Livewire::actingAs($this->user)
        ->test(Dashboard::class)
        ->assertSeeHtml('data-test="dashboard-base-currency"')
        ->assertSee('Owed, Available, Needed and the buffer are in USD');

    config(['budget.multi_currency' => false]);

    Livewire::actingAs($this->user)
        ->test(Dashboard::class)
        ->assertDontSeeHtml('data-test="dashboard-base-currency"');
});
