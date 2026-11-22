<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\RecordTrade;
use App\Enums\TradeActivity;
use App\Enums\TransactionDirection;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Holding;
use App\Models\Security;
use App\Models\SecurityPrice;
use App\Models\Trade;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Investments\CostBasisResolver;
use App\Services\Investments\HoldingSnapshotter;
use App\Services\Investments\InvestmentPortfolio;
use App\Services\Investments\TradeRecorder;
use App\Support\Investments\Decimal;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-31 12:00:00'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->investment()->for($this->user)->create(['balance' => 50000]);
    $this->security = Security::factory()->create(['currency' => 'AUD']);
    $this->recorder = app(TradeRecorder::class);
});

function trade(mixed $test, TradeActivity $activity, string $quantity, int $price, string $date = '2026-03-31'): void
{
    $test->recorder->record($test->user, new RecordTrade(accountId: $test->account->id, activity: $activity, date: $date, securityId: $test->security->id, quantity: $quantity, price: $price));
}

function price(mixed $test, string $date, int $cents): void
{
    SecurityPrice::factory()->create(['security_id' => $test->security->id, 'currency' => 'AUD', 'date' => $date, 'price' => $cents]);
}

it('keeps the holding in step with the trades and removes it when sold to zero', function () {
    trade($this, TradeActivity::Buy, '10', 1500);
    trade($this, TradeActivity::Buy, '10', 1700);

    $holding = Holding::query()->sole();

    expect(Decimal::trim($holding->quantity))->toBe('20')
        ->and($holding->amount)->toBe(32000)
        ->and($holding->cost_basis_per_share)->toBe(1600);

    price($this, '2026-03-31', 2000);
    trade($this, TradeActivity::Sell, '5', 2000);

    expect(Decimal::trim(Holding::query()->sole()->quantity))->toBe('15')
        ->and(Holding::query()->sole()->amount)->toBe(30000);

    trade($this, TradeActivity::Sell, '15', 2000);

    expect(Holding::query()->count())->toBe(0);
});

it('values holdings at the latest price with weights and returns', function () {
    trade($this, TradeActivity::Buy, '10', 1000);
    price($this, '2026-03-01', 800);
    price($this, '2026-03-30', 1100);
    price($this, '2026-03-31', 1200);
    $other = Security::factory()->create(['currency' => 'AUD']);
    Holding::factory()->create(['account_id' => $this->account->id, 'security_id' => $other->id, 'currency' => 'AUD', 'quantity' => '1', 'amount' => 3000, 'date' => '2026-03-31']);

    $statement = app(InvestmentPortfolio::class)->statement($this->user, $this->account);
    $first = $statement['rows'][0];

    expect($statement['holdingsValue'])->toBe(15000)
        ->and($statement['portfolioValue'])->toBe(50000)
        ->and($statement['cash'])->toBe(35000)
        ->and($first['value'])->toBe(12000)
        ->and($first['weightBp'])->toBe(8000)
        ->and($first['unrealised'])->toBe(2000)
        ->and($first['returnBp'])->toBe(2000)
        ->and($statement['dayChange'])->toBe(1000)
        ->and($statement['periodReturnBp'])->toBe(5000)
        ->and($statement['rows'][1]['returnBp'])->toBeNull()
        ->and($statement['rows'][1]['costPerShare'])->toBeNull();
});

it('counts dividends and interest and leaves unknown figures null', function () {
    $this->recorder->record($this->user, new RecordTrade(accountId: $this->account->id, activity: TradeActivity::Dividend, date: '2026-03-20', securityId: $this->security->id, amount: 1250));
    $this->recorder->record($this->user, new RecordTrade(accountId: $this->account->id, activity: TradeActivity::Interest, date: '2026-03-21', amount: 400));

    $statement = app(InvestmentPortfolio::class)->statement($this->user, $this->account);

    expect($statement['dividends'])->toBe(1250)
        ->and($statement['interest'])->toBe(400)
        ->and($statement['rows'])->toBe([])
        ->and($statement['dayChange'])->toBeNull()
        ->and($statement['unrealised'])->toBeNull()
        ->and($statement['periodReturnBp'])->toBeNull()
        ->and($statement['portfolioValue'])->toBe(50000);
});

it('ignores another user\'s holdings', function () {
    $other = Account::factory()->investment()->for(User::factory()->create())->create();
    Holding::factory()->create(['account_id' => $other->id, 'security_id' => $this->security->id, 'quantity' => '5', 'amount' => 9000]);

    expect(app(InvestmentPortfolio::class)->rows($this->user, [$other]))->toBe([])
        ->and(app(InvestmentPortfolio::class)->rows($other->user, [$other]))->toHaveCount(1);
});

it('turns typed dollars into cents', function (?int $expected, string $typed) {
    expect(Decimal::dollarsToCents($typed))->toBe($expected);
})->with([
    [1500, '15'],
    [150050, '1,500.50'],
    [1550, ' 15.5 '],
    [1, '0.005'],
    [null, '-3'],
    [null, 'abc'],
    [null, ''],
]);

it('drops a position sold to zero on a later day', function () {
    trade($this, TradeActivity::Buy, '10', 1500, '2026-03-01');
    $this->travelTo(CarbonImmutable::parse('2026-03-31 12:00:00'));
    trade($this, TradeActivity::Sell, '10', 1800, '2026-03-31');

    expect(Holding::query()->count())->toBe(0)
        ->and(app(InvestmentPortfolio::class)->rows($this->user, [$this->account]))->toBe([]);
});

it('keeps a manual cost basis and the remap origin when a later trade moves the position forward', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 12:00:00'));
    trade($this, TradeActivity::Buy, '10', 1500, '2026-03-01');
    $holding = Holding::query()->sole();
    app(CostBasisResolver::class)->setManual($this->user, $holding, 20000);
    $holding->forceFill(['original_security_id' => Security::factory()->create()->id])->save();

    $this->travelTo(CarbonImmutable::parse('2026-03-31 12:00:00'));
    trade($this, TradeActivity::Buy, '10', 1700, '2026-03-31');

    $current = Holding::query()->sole();

    expect(Decimal::trim($current->quantity))->toBe('20')
        ->and($current->date->toDateString())->toBe('2026-03-31')
        ->and($current->cost_basis_locked)->toBeTrue()
        ->and($current->cost_basis_per_share)->toBe(2000)
        ->and($current->original_security_id)->toBe($holding->original_security_id);
});

it('counts contributions by direction, once per split, in absolute amounts', function () {
    $credit = Transaction::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->account->id, 'amount' => 100000, 'direction' => TransactionDirection::Credit, 'status' => TransactionStatus::Posted]);
    Transaction::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->account->id, 'amount' => -20000, 'direction' => TransactionDirection::Debit, 'status' => TransactionStatus::Posted]);
    Transaction::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->account->id, 'amount' => 100000, 'direction' => TransactionDirection::Credit, 'status' => TransactionStatus::Posted, 'split_parent_id' => $credit->id]);

    expect(app(InvestmentPortfolio::class)->contributions($this->user, $this->account))->toBe(80000);
});

it('leaves the holdings of a provider-linked account to the provider', function () {
    $linked = Account::factory()->investment()->withRedbark()->for($this->user)->create();
    $holding = Holding::factory()->create(['account_id' => $linked->id, 'security_id' => $this->security->id, 'currency' => 'AUD', 'quantity' => '7', 'amount' => 7000]);
    Trade::factory()->create(['account_id' => $linked->id, 'security_id' => $this->security->id, 'quantity' => '3']);

    app(HoldingSnapshotter::class)->refresh($this->user, $linked->id, $this->security->id, 'AUD');

    expect(Decimal::trim($holding->fresh()->quantity))->toBe('7');
});
