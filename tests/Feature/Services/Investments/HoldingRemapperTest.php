<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TradeActivity;
use App\Exceptions\HoldingRefusedException;
use App\Models\Account;
use App\Models\Holding;
use App\Models\Security;
use App\Models\Trade;
use App\Models\User;
use App\Services\Investments\HoldingRemapper;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->investment()->for($this->user)->create();
    $this->wrong = Security::factory()->create();
    $this->right = Security::factory()->create();
    $this->remapper = app(HoldingRemapper::class);
});

function remapHolding(Account $account, Security $security, string $quantity, int $amount, ?int $cost, string $date = '2026-03-10', string $currency = 'AUD'): Holding
{
    return Holding::factory()->create([
        'account_id' => $account->id,
        'security_id' => $security->id,
        'date' => $date,
        'quantity' => $quantity,
        'amount' => $amount,
        'currency' => $currency,
        'cost_basis_per_share' => $cost,
    ]);
}

it('merges colliding holdings on remap', function () {
    $wrong = remapHolding($this->account, $this->wrong, '10.00000000', 11000, 1000);
    $right = remapHolding($this->account, $this->right, '5.00000000', 6000, 1600);

    $result = $this->remapper->remap($this->user, $wrong, $this->right);

    expect($result)->toHaveCount(1)
        ->and($result->first()->id)->toBe($right->id)
        ->and($result->first()->quantity)->toBe('15.00000000')
        ->and($result->first()->amount)->toBe(17000)
        ->and($result->first()->cost_basis_per_share)->toBe(1200)
        ->and($result->first()->provider_locked)->toBeTrue()
        ->and(Holding::query()->whereKey($wrong->id)->exists())->toBeFalse();
});

it('does not merge holdings on another day or in another currency', function () {
    $wrong = remapHolding($this->account, $this->wrong, '10.00000000', 11000, 1000);
    remapHolding($this->account, $this->right, '5.00000000', 6000, 1600, '2026-03-11');
    remapHolding($this->account, $this->right, '5.00000000', 6000, 1600, '2026-03-10', 'USD');

    $result = $this->remapper->remap($this->user, $wrong, $this->right);

    expect($result)->toHaveCount(3)
        ->and($wrong->fresh()->security_id)->toBe($this->right->id);
});

it('moves a holding with no collision, locks it from the provider and remembers where it came from', function () {
    $wrong = remapHolding($this->account, $this->wrong, '10.00000000', 11000, 1000);

    $this->remapper->remap($this->user, $wrong, $this->right);

    expect($wrong->fresh()->security_id)->toBe($this->right->id)
        ->and($wrong->fresh()->original_security_id)->toBe($this->wrong->id)
        ->and($wrong->fresh()->provider_locked)->toBeTrue();
});

it('moves the account\'s trades and every dated holding of the old security', function () {
    $first = remapHolding($this->account, $this->wrong, '10.00000000', 11000, 1000, '2026-03-10');
    $second = remapHolding($this->account, $this->wrong, '10.00000000', 12000, 1000, '2026-03-11');
    $trade = Trade::factory()->create(['account_id' => $this->account->id, 'security_id' => $this->wrong->id, 'activity' => TradeActivity::Buy]);
    $otherAccount = Account::factory()->investment()->for($this->user)->create();
    $untouched = Trade::factory()->create(['account_id' => $otherAccount->id, 'security_id' => $this->wrong->id]);

    $this->remapper->remap($this->user, $first, $this->right);

    expect($second->fresh()->security_id)->toBe($this->right->id)
        ->and($trade->fresh()->security_id)->toBe($this->right->id)
        ->and($untouched->fresh()->security_id)->toBe($this->wrong->id);
});

it('has no cost after a merge when either side has none', function () {
    $wrong = remapHolding($this->account, $this->wrong, '10.00000000', 11000, null);
    remapHolding($this->account, $this->right, '5.00000000', 6000, 1600);

    $result = $this->remapper->remap($this->user, $wrong, $this->right);

    expect($result->first()->cost_basis_per_share)->toBeNull()
        ->and($result->first()->quantity)->toBe('15.00000000');
});

it('does nothing when the target is the current security', function () {
    $holding = remapHolding($this->account, $this->wrong, '10.00000000', 11000, 1000);

    $this->remapper->remap($this->user, $holding, $this->wrong);

    expect($holding->fresh()->original_security_id)->toBeNull()
        ->and($holding->fresh()->provider_locked)->toBeFalse();
});

it('resets a remapped holding back to the original security', function () {
    $wrong = remapHolding($this->account, $this->wrong, '10.00000000', 11000, 1000);
    $trade = Trade::factory()->create(['account_id' => $this->account->id, 'security_id' => $this->wrong->id]);

    $this->remapper->remap($this->user, $wrong, $this->right);
    $this->remapper->reset($this->user, $wrong->fresh());

    expect($wrong->fresh()->security_id)->toBe($this->wrong->id)
        ->and($wrong->fresh()->original_security_id)->toBeNull()
        ->and($wrong->fresh()->provider_locked)->toBeFalse()
        ->and($trade->fresh()->security_id)->toBe($this->wrong->id);
});

it('keeps the first original security across two remaps', function () {
    $third = Security::factory()->create();
    $wrong = remapHolding($this->account, $this->wrong, '10.00000000', 11000, 1000);

    $this->remapper->remap($this->user, $wrong, $this->right);
    $this->remapper->remap($this->user, $wrong->fresh(), $third);

    expect($wrong->fresh()->original_security_id)->toBe($this->wrong->id)
        ->and($wrong->fresh()->security_id)->toBe($third->id);
});

it('refuses to reset a holding that was never remapped', function () {
    $holding = remapHolding($this->account, $this->wrong, '10.00000000', 11000, 1000);

    expect(fn () => $this->remapper->reset($this->user, $holding))->toThrow(HoldingRefusedException::class);
});

it('refuses to remap another user\'s holding and changes nothing', function () {
    $stranger = Account::factory()->investment()->create();
    $theirs = remapHolding($stranger, $this->wrong, '10.00000000', 11000, 1000);

    expect(fn () => $this->remapper->remap($this->user, $theirs, $this->right))->toThrow(HoldingRefusedException::class)
        ->and($theirs->fresh()->security_id)->toBe($this->wrong->id);
});

it('puts a holding remapped back to its original security straight back without deleting it', function () {
    $wrong = remapHolding($this->account, $this->wrong, '10.00000000', 11000, 1000);

    $this->remapper->remap($this->user, $wrong, $this->right);
    $this->remapper->remap($this->user, $wrong->fresh(), $this->wrong);

    expect($wrong->fresh()->security_id)->toBe($this->wrong->id)
        ->and($wrong->fresh()->original_security_id)->toBeNull()
        ->and($wrong->fresh()->provider_locked)->toBeFalse()
        ->and($wrong->fresh()->quantity)->toBe('10.00000000');

    expect(fn () => $this->remapper->reset($this->user, $wrong->fresh()))->toThrow(HoldingRefusedException::class)
        ->and(Holding::query()->whereKey($wrong->id)->exists())->toBeTrue();
});

it('resets only the holdings and trades that were remapped, not the target security\'s own', function () {
    $wrong = remapHolding($this->account, $this->wrong, '10.00000000', 11000, 1000, '2026-03-10');
    remapHolding($this->account, $this->right, '4.00000000', 5000, 1250, '2026-03-11');
    $genuineTrade = Trade::factory()->create(['account_id' => $this->account->id, 'security_id' => $this->right->id, 'quantity' => '4.00000000']);
    $wrongTrade = Trade::factory()->create(['account_id' => $this->account->id, 'security_id' => $this->wrong->id]);

    $this->remapper->remap($this->user, $wrong, $this->right);

    $position = Holding::query()->where('security_id', $this->right->id)->sole();

    expect($position->quantity)->toBe('14.00000000')
        ->and($position->original_security_id)->toBe($this->wrong->id);

    $this->remapper->reset($this->user, $position);

    expect(Holding::query()->where('security_id', $this->wrong->id)->sole()->quantity)->toBe('10.00000000')
        ->and(Holding::query()->where('security_id', $this->right->id)->sole()->quantity)->toBe('4.00000000')
        ->and($genuineTrade->fresh()->security_id)->toBe($this->right->id)
        ->and($wrongTrade->fresh()->security_id)->toBe($this->wrong->id)
        ->and($wrongTrade->fresh()->original_security_id)->toBeNull();
});

it('merges into the original security\'s own holding when a reset lands on the same day', function () {
    $wrong = remapHolding($this->account, $this->wrong, '10.00000000', 11000, 1000, '2026-03-10');

    $this->remapper->remap($this->user, $wrong, $this->right);
    $created = remapHolding($this->account, $this->wrong, '2.00000000', 2200, 1000, '2026-03-10');

    $result = $this->remapper->reset($this->user, $wrong->fresh());

    expect($result)->toHaveCount(1)
        ->and($result->first()->id)->toBe($created->id)
        ->and($result->first()->quantity)->toBe('12.00000000')
        ->and(Holding::query()->whereKey($wrong->id)->exists())->toBeFalse();
});

it('keeps each row\'s own original security when several are remapped again', function () {
    $third = Security::factory()->create();
    $remapped = remapHolding($this->account, $this->wrong, '10.00000000', 11000, 1000, '2026-03-10');
    $genuine = remapHolding($this->account, $this->right, '4.00000000', 5000, 1250, '2026-03-11');

    $this->remapper->remap($this->user, $remapped, $this->right);
    $this->remapper->remap($this->user, $genuine->fresh(), $third);

    expect($remapped->fresh()->original_security_id)->toBe($this->wrong->id)
        ->and($genuine->fresh()->original_security_id)->toBe($this->right->id);
});

it('renames moved trades that carried the old ticker and leaves custom names alone', function () {
    $holding = remapHolding($this->account, $this->wrong, '10.00000000', 11000, 1000);
    $named = Trade::factory()->create(['account_id' => $this->account->id, 'security_id' => $this->wrong->id, 'quantity' => '10.00000000', 'activity' => TradeActivity::Buy, 'name' => 'Buy 10 '.$this->wrong->ticker]);
    $custom = Trade::factory()->create(['account_id' => $this->account->id, 'security_id' => $this->wrong->id, 'activity' => TradeActivity::Buy, 'name' => 'My own note']);

    $this->remapper->remap($this->user, $holding, $this->right);

    expect($named->fresh()->name)->toBe('Buy 10 '.$this->right->ticker)
        ->and($custom->fresh()->name)->toBe('My own note');
});

it('drops a locked or manual cost source when a merge has no known cost', function () {
    $wrong = remapHolding($this->account, $this->wrong, '10.00000000', 11000, null);
    $right = remapHolding($this->account, $this->right, '5.00000000', 6000, 1600);
    $right->update(['cost_basis_source' => 'manual', 'cost_basis_locked' => true]);

    $result = $this->remapper->remap($this->user, $wrong, $this->right);

    expect($result->first()->cost_basis_per_share)->toBeNull()
        ->and($result->first()->cost_basis_source)->toBeNull()
        ->and($result->first()->cost_basis_locked)->toBeFalse();
});
