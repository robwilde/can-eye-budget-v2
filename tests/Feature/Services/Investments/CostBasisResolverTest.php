<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CostBasisSource;
use App\Enums\TradeActivity;
use App\Exceptions\HoldingRefusedException;
use App\Models\Account;
use App\Models\Holding;
use App\Models\Security;
use App\Models\Trade;
use App\Services\Investments\CostBasisResolver;

beforeEach(function (): void {
    $this->account = Account::factory()->investment()->create();
    $this->user = $this->account->user;
    $this->security = Security::factory()->create();
    $this->resolver = app(CostBasisResolver::class);
});

function cbTrade(Account $account, Security $security, TradeActivity $activity, string $quantity, int $price, int $fee, string $date): Trade
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
        'activity' => $activity,
    ]);
}

function cbHolding(Account $account, Security $security, string $quantity = '10.00000000', array $overrides = []): Holding
{
    return Holding::factory()->create(array_merge(['account_id' => $account->id, 'security_id' => $security->id, 'quantity' => $quantity], $overrides));
}

it('stores a manual cost basis per share and locks it', function () {
    $holding = cbHolding($this->account, $this->security);

    $saved = $this->resolver->setManual($this->user, $holding, 10000);

    expect($saved->fresh()->cost_basis_per_share)->toBe(1000)
        ->and($saved->fresh()->cost_basis_source)->toBe(CostBasisSource::Manual)
        ->and($saved->fresh()->cost_basis_locked)->toBeTrue();
});

it('keeps a locked cost basis when a provider value arrives', function () {
    $holding = $this->resolver->setManual($this->user, cbHolding($this->account, $this->security), 10000);

    $this->resolver->applyProviderValue($this->user, $holding, 9999);

    expect($holding->fresh()->cost_basis_per_share)->toBe(1000)
        ->and($holding->fresh()->cost_basis_source)->toBe(CostBasisSource::Manual);
});

it('lets only a calculated value replace a manual cost basis once it is unlocked', function () {
    cbTrade($this->account, $this->security, TradeActivity::Buy, '10', 2000, 0, '2026-01-05');
    $holding = $this->resolver->setManual($this->user, cbHolding($this->account, $this->security), 10000);

    $this->resolver->unlock($this->user, $holding);
    $this->resolver->applyProviderValue($this->user, $holding->fresh(), 5555);

    expect($holding->fresh()->cost_basis_per_share)->toBe(1000);

    $this->resolver->applyCalculated($this->user, $holding->fresh());

    expect($holding->fresh()->cost_basis_per_share)->toBe(2000)
        ->and($holding->fresh()->cost_basis_source)->toBe(CostBasisSource::Calculated);
});

it('does not replace a locked cost basis with a calculated one', function () {
    cbTrade($this->account, $this->security, TradeActivity::Buy, '10', 2000, 0, '2026-01-05');
    $holding = $this->resolver->setManual($this->user, cbHolding($this->account, $this->security), 10000);

    $this->resolver->applyCalculated($this->user, $holding);

    expect($holding->fresh()->cost_basis_per_share)->toBe(1000);
});

it('refuses a manual cost basis on a holding with no shares', function () {
    $holding = cbHolding($this->account, $this->security, '0.00000000');

    expect(fn () => $this->resolver->setManual($this->user, $holding, 10000))->toThrow(HoldingRefusedException::class);
});

it('calculates the average cost from several buys with fees in the cost', function () {
    cbTrade($this->account, $this->security, TradeActivity::Buy, '10', 1000, 500, '2026-01-05');
    cbTrade($this->account, $this->security, TradeActivity::Buy, '10', 2000, 500, '2026-01-06');

    expect($this->resolver->calculated($this->account->id, $this->security->id, 'AUD'))->toBe(1550);
});

it('keeps the average cost after a partial sell', function () {
    cbTrade($this->account, $this->security, TradeActivity::Buy, '10', 1000, 0, '2026-01-05');
    cbTrade($this->account, $this->security, TradeActivity::Sell, '4', 3000, 0, '2026-01-06');

    $position = $this->resolver->position($this->account->id, $this->security->id, 'AUD');

    expect($position['quantity'])->toBe('6.00000000')
        ->and($this->resolver->calculated($this->account->id, $this->security->id, 'AUD'))->toBe(1000);
});

it('sells across lots at the running average', function () {
    cbTrade($this->account, $this->security, TradeActivity::Buy, '10', 1000, 0, '2026-01-05');
    cbTrade($this->account, $this->security, TradeActivity::Buy, '10', 2000, 0, '2026-01-06');
    cbTrade($this->account, $this->security, TradeActivity::Sell, '15', 3000, 0, '2026-01-07');

    $position = $this->resolver->position($this->account->id, $this->security->id, 'AUD');

    expect($position['quantity'])->toBe('5.00000000')
        ->and(cbCost($position))->toBe(7500)
        ->and($this->resolver->calculated($this->account->id, $this->security->id, 'AUD'))->toBe(1500);
});

function cbCost(array $position): int
{
    return App\Support\Investments\Decimal::toCents($position['cost']);
}

it('floors a position at zero when more is sold than was held', function () {
    cbTrade($this->account, $this->security, TradeActivity::Buy, '5', 1000, 0, '2026-01-05');
    cbTrade($this->account, $this->security, TradeActivity::Sell, '8', 3000, 0, '2026-01-06');

    $position = $this->resolver->position($this->account->id, $this->security->id, 'AUD');

    expect($position['quantity'])->toBe('0.00000000')
        ->and($position['oversold'])->toBeTrue()
        ->and($this->resolver->calculated($this->account->id, $this->security->id, 'AUD'))->toBeNull();
});

it('treats shares that arrived by a sweep in as an unknown cost', function () {
    cbTrade($this->account, $this->security, TradeActivity::Buy, '5', 1000, 0, '2026-01-05');
    cbTrade($this->account, $this->security, TradeActivity::SweepIn, '5', 1100, 0, '2026-01-06');

    expect($this->resolver->calculated($this->account->id, $this->security->id, 'AUD'))->toBeNull();
});

it('counts a reinvestment as a purchase at its price', function () {
    cbTrade($this->account, $this->security, TradeActivity::Buy, '10', 1000, 0, '2026-01-05');
    cbTrade($this->account, $this->security, TradeActivity::Reinvestment, '10', 2000, 0, '2026-02-05');

    expect($this->resolver->calculated($this->account->id, $this->security->id, 'AUD'))->toBe(1500);
});

it('resolves manual first, then calculated, then provider', function () {
    $manual = cbHolding($this->account, $this->security, '10.00000000', ['cost_basis_per_share' => 700, 'cost_basis_source' => 'manual']);

    expect($this->resolver->resolve($manual))->toBe(['perShare' => 700, 'source' => CostBasisSource::Manual]);

    $other = Security::factory()->create();
    $provider = cbHolding($this->account, $other, '10.00000000', ['cost_basis_per_share' => 800, 'cost_basis_source' => 'provider']);

    expect($this->resolver->resolve($provider))->toBe(['perShare' => 800, 'source' => CostBasisSource::Provider]);

    cbTrade($this->account, $other, TradeActivity::Buy, '10', 900, 0, '2026-01-05');

    expect($this->resolver->resolve($provider->fresh()))->toBe(['perShare' => 900, 'source' => CostBasisSource::Calculated]);
});

it('has no cost when nothing is known', function () {
    expect($this->resolver->resolve(cbHolding($this->account, $this->security)))->toBeNull();
});

it('ignores another account\'s trades in the same security', function () {
    $other = Account::factory()->investment()->create();
    cbTrade($other, $this->security, TradeActivity::Buy, '10', 9999, 0, '2026-01-05');
    cbTrade($this->account, $this->security, TradeActivity::Buy, '10', 1000, 0, '2026-01-05');

    expect($this->resolver->calculated($this->account->id, $this->security->id, 'AUD'))->toBe(1000);
});

it('starts a known position again once an unknown one is sold down to zero', function () {
    cbTrade($this->account, $this->security, TradeActivity::SweepIn, '10', 1000, 0, '2026-01-05');
    cbTrade($this->account, $this->security, TradeActivity::SweepOut, '10', 1200, 0, '2026-01-06');
    cbTrade($this->account, $this->security, TradeActivity::Buy, '10', 1500, 0, '2026-01-07');

    expect($this->resolver->calculated($this->account->id, $this->security->id, 'AUD'))->toBe(1500);
});

it('keeps trades in different currencies apart when working out the cost', function () {
    cbTrade($this->account, $this->security, TradeActivity::Buy, '10', 1000, 0, '2026-01-05');
    Trade::factory()->create(['account_id' => $this->account->id, 'security_id' => $this->security->id, 'date' => '2026-01-06', 'quantity' => '10', 'price' => 9000, 'fee' => 0, 'amount' => 90000, 'currency' => 'USD', 'activity' => TradeActivity::Buy]);

    expect($this->resolver->calculated($this->account->id, $this->security->id, 'AUD'))->toBe(1000)
        ->and($this->resolver->calculated($this->account->id, $this->security->id, 'USD'))->toBe(9000);
});

it('does not let a stale copy of a holding overwrite a cost locked in the meantime', function () {
    $holding = cbHolding($this->account, $this->security);
    $stale = Holding::query()->find($holding->id);

    $this->resolver->setManual($this->user, $holding, 10000);
    $this->resolver->applyProviderValue($this->user, $stale, 950);
    $this->resolver->applyCalculated($this->user, $stale);

    expect($holding->fresh()->cost_basis_per_share)->toBe(1000)
        ->and($holding->fresh()->cost_basis_source)->toBe(CostBasisSource::Manual)
        ->and($holding->fresh()->cost_basis_locked)->toBeTrue();
});

it('checks the zero quantity on the locked row, not the caller\'s copy', function () {
    $holding = cbHolding($this->account, $this->security);
    $stale = Holding::query()->find($holding->id);
    $holding->update(['quantity' => '0.00000000']);

    expect(fn () => $this->resolver->setManual($this->user, $stale, 10000))->toThrow(HoldingRefusedException::class);
});

it('refuses every cost basis write on another user\'s holding', function () {
    $stranger = Account::factory()->investment()->create();
    $theirs = cbHolding($stranger, $this->security);

    expect(fn () => $this->resolver->setManual($this->user, $theirs, 10000))->toThrow(HoldingRefusedException::class)
        ->and(fn () => $this->resolver->unlock($this->user, $theirs))->toThrow(HoldingRefusedException::class)
        ->and(fn () => $this->resolver->applyProviderValue($this->user, $theirs, 500))->toThrow(HoldingRefusedException::class)
        ->and(fn () => $this->resolver->applyCalculated($this->user, $theirs))->toThrow(HoldingRefusedException::class)
        ->and($theirs->fresh()->cost_basis_per_share)->toBeNull();
});
