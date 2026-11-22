<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TradeActivity;
use App\Exceptions\HoldingRefusedException;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\Holding;
use App\Models\Security;
use App\Models\SecurityPrice;
use App\Models\Trade;
use App\Models\User;
use App\Services\Investments\GainsCalculator;

beforeEach(function (): void {
    $this->user = User::factory()->create(['base_currency' => 'AUD']);
    $this->account = Account::factory()->investment()->for($this->user)->create();
    $this->security = Security::factory()->create();
    $this->gains = app(GainsCalculator::class);
});

function gcTrade(Account $account, Security $security, TradeActivity $activity, string $quantity, int $price, int $fee, string $date, string $currency = 'AUD'): Trade
{
    $signed = $activity->reducesPosition() ? '-'.mb_ltrim($quantity, '-') : $quantity;

    return Trade::factory()->create([
        'account_id' => $account->id,
        'security_id' => $security->id,
        'date' => $date,
        'quantity' => $signed,
        'price' => $price,
        'fee' => $fee,
        'amount' => (int) round((float) $signed * $price) + $fee,
        'currency' => $currency,
        'activity' => $activity,
    ]);
}

it('computes realised gain on a sell net of fees', function () {
    gcTrade($this->account, $this->security, TradeActivity::Buy, '10', 1500, 0, '2026-01-05');
    $sell = gcTrade($this->account, $this->security, TradeActivity::Sell, '10', 2000, 500, '2026-02-05');

    expect($this->gains->realised($this->user, $sell))->toBe(4500);
});

it('realises a gain on part of a position at the average cost', function () {
    gcTrade($this->account, $this->security, TradeActivity::Buy, '10', 1000, 0, '2026-01-05');
    gcTrade($this->account, $this->security, TradeActivity::Buy, '10', 2000, 0, '2026-01-06');
    $sell = gcTrade($this->account, $this->security, TradeActivity::Sell, '15', 3000, 0, '2026-01-07');

    expect($this->gains->realised($this->user, $sell))->toBe(22500);

    $second = gcTrade($this->account, $this->security, TradeActivity::Sell, '5', 1000, 0, '2026-01-08');

    expect($this->gains->realised($this->user, $second))->toBe(-2500);
});

it('includes buy fees in the cost of the shares sold', function () {
    gcTrade($this->account, $this->security, TradeActivity::Buy, '10', 1000, 1000, '2026-01-05');
    $sell = gcTrade($this->account, $this->security, TradeActivity::Sell, '10', 1000, 0, '2026-01-06');

    expect($this->gains->realised($this->user, $sell))->toBe(-1000);
});

it('does not realise a gain on a sweep out, a buy or a transfer', function () {
    gcTrade($this->account, $this->security, TradeActivity::Buy, '10', 1000, 0, '2026-01-05');
    $sweep = gcTrade($this->account, $this->security, TradeActivity::SweepOut, '5', 2000, 0, '2026-01-06');
    $buy = gcTrade($this->account, $this->security, TradeActivity::Buy, '1', 1000, 0, '2026-01-07');
    $dividend = Trade::factory()->create(['account_id' => $this->account->id, 'security_id' => $this->security->id, 'activity' => TradeActivity::Dividend, 'quantity' => '0', 'amount' => -500]);

    expect($this->gains->realised($this->user, $sweep))->toBeNull()
        ->and($this->gains->realised($this->user, $buy))->toBeNull()
        ->and($this->gains->realised($this->user, $dividend))->toBeNull();
});

it('withholds the gain on a sell beyond holdings', function () {
    gcTrade($this->account, $this->security, TradeActivity::Buy, '5', 1000, 0, '2026-01-05');
    $sell = gcTrade($this->account, $this->security, TradeActivity::Sell, '8', 2000, 0, '2026-01-06');

    expect($this->gains->realised($this->user, $sell))->toBeNull();
});

it('withholds the gain on a sell with no earlier purchase', function () {
    $sell = gcTrade($this->account, $this->security, TradeActivity::Sell, '8', 2000, 0, '2026-01-06');

    expect($this->gains->realised($this->user, $sell))->toBeNull();
});

it('withholds the gain when shares arrived through an internal movement', function () {
    gcTrade($this->account, $this->security, TradeActivity::SweepIn, '10', 1000, 0, '2026-01-05');
    $sell = gcTrade($this->account, $this->security, TradeActivity::Sell, '5', 2000, 0, '2026-01-06');

    expect($this->gains->realised($this->user, $sell))->toBeNull();
});

it('only counts trades before the sell when working out its cost', function () {
    gcTrade($this->account, $this->security, TradeActivity::Buy, '10', 1000, 0, '2026-01-05');
    $sell = gcTrade($this->account, $this->security, TradeActivity::Sell, '10', 2000, 0, '2026-01-06');
    gcTrade($this->account, $this->security, TradeActivity::Buy, '10', 9000, 0, '2026-01-07');

    expect($this->gains->realised($this->user, $sell))->toBe(10000);
});

it('converts a gain in another currency at the rate of the trade date', function () {
    ExchangeRate::factory()->create(['from_currency' => 'USD', 'to_currency' => 'AUD', 'rate_date' => '2026-02-05', 'rate' => 1.5]);
    gcTrade($this->account, $this->security, TradeActivity::Buy, '10', 1000, 0, '2026-02-04', 'USD');
    $sell = gcTrade($this->account, $this->security, TradeActivity::Sell, '10', 1200, 0, '2026-02-05', 'USD');

    expect($this->gains->realised($this->user, $sell))->toBe(3000);
});

it('withholds a gain in another currency when no rate is known', function () {
    config(['services.exchange_rates.enabled' => false]);
    gcTrade($this->account, $this->security, TradeActivity::Buy, '10', 1000, 0, '2026-02-04', 'NZD');
    $sell = gcTrade($this->account, $this->security, TradeActivity::Sell, '10', 1200, 0, '2026-02-05', 'NZD');

    expect($this->gains->realised($this->user, $sell))->toBeNull();
});

it('refuses to work out a gain on another user\'s trade', function () {
    $stranger = Account::factory()->investment()->create();
    $sell = gcTrade($stranger, $this->security, TradeActivity::Sell, '1', 1000, 0, '2026-02-05');

    expect(fn () => $this->gains->realised($this->user, $sell))->toThrow(HoldingRefusedException::class);
});

it('computes unrealised gain from the latest price', function () {
    $holding = Holding::factory()->create(['account_id' => $this->account->id, 'security_id' => $this->security->id, 'quantity' => '10.00000000', 'cost_basis_per_share' => 1000, 'cost_basis_source' => 'manual']);
    SecurityPrice::factory()->create(['security_id' => $this->security->id, 'date' => '2026-03-01', 'price' => 1100]);
    SecurityPrice::factory()->create(['security_id' => $this->security->id, 'date' => '2026-03-02', 'price' => 1200]);
    SecurityPrice::factory()->create(['security_id' => $this->security->id, 'date' => '2026-03-03', 'price' => 900, 'currency' => 'USD']);

    expect($this->gains->unrealised($this->user, $holding))->toBe(2000)
        ->and($this->gains->unrealised($this->user, $holding, 800))->toBe(-2000);
});

it('has no unrealised gain without a cost or a price', function () {
    $holding = Holding::factory()->create(['account_id' => $this->account->id, 'security_id' => $this->security->id]);

    expect($this->gains->unrealised($this->user, $holding, 1200))->toBeNull();

    $holding->update(['cost_basis_per_share' => 1000, 'cost_basis_source' => 'manual']);

    expect($this->gains->unrealised($this->user, $holding->fresh()))->toBeNull();
});

it('rounds a fractional quantity of shares to whole cents', function () {
    $holding = Holding::factory()->create(['account_id' => $this->account->id, 'security_id' => $this->security->id, 'quantity' => '0.12345678', 'cost_basis_per_share' => 1000, 'cost_basis_source' => 'manual']);

    expect($this->gains->unrealised($this->user, $holding, 1200))->toBe(25);
});

it('refuses to work out an unrealised gain on another user\'s holding', function () {
    $stranger = Account::factory()->investment()->create();
    $theirs = Holding::factory()->create(['account_id' => $stranger->id, 'security_id' => $this->security->id]);

    expect(fn () => $this->gains->unrealised($this->user, $theirs, 1200))->toThrow(HoldingRefusedException::class);
});

it('does not mix purchases in another currency into the cost of a sale', function () {
    gcTrade($this->account, $this->security, TradeActivity::Buy, '10', 1000, 0, '2026-02-01');
    gcTrade($this->account, $this->security, TradeActivity::Buy, '10', 90000, 0, '2026-02-02', 'USD');
    $sell = gcTrade($this->account, $this->security, TradeActivity::Sell, '10', 1500, 0, '2026-02-05');

    expect($this->gains->realised($this->user, $sell))->toBe(5000);
});
