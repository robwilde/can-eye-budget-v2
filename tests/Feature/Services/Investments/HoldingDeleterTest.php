<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Exceptions\HoldingRefusedException;
use App\Models\Account;
use App\Models\Holding;
use App\Models\Security;
use App\Models\Trade;
use App\Models\User;
use App\Services\Investments\HoldingDeleter;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->security = Security::factory()->create();
    $this->deleter = app(HoldingDeleter::class);
});

it('deletes a holding with its trades on a manual account', function () {
    $account = Account::factory()->investment()->for($this->user)->create();
    $holding = Holding::factory()->create(['account_id' => $account->id, 'security_id' => $this->security->id]);
    Holding::factory()->create(['account_id' => $account->id, 'security_id' => $this->security->id, 'date' => '2026-01-01']);
    Trade::factory()->count(2)->create(['account_id' => $account->id, 'security_id' => $this->security->id]);
    $keptSecurity = Security::factory()->create();
    $kept = Holding::factory()->create(['account_id' => $account->id, 'security_id' => $keptSecurity->id]);
    $keptTrade = Trade::factory()->create(['account_id' => $account->id, 'security_id' => $keptSecurity->id]);

    $this->deleter->delete($this->user, $holding);

    expect(Holding::query()->where('security_id', $this->security->id)->count())->toBe(0)
        ->and(Trade::query()->where('security_id', $this->security->id)->count())->toBe(0)
        ->and($kept->fresh())->not->toBeNull()
        ->and($keptTrade->fresh())->not->toBeNull();
});

it('refuses when a linked provider would recreate the holding', function () {
    $account = Account::factory()->investment()->withRedbark()->for($this->user)->create();
    $holding = Holding::factory()->create(['account_id' => $account->id, 'security_id' => $this->security->id]);
    $trade = Trade::factory()->create(['account_id' => $account->id, 'security_id' => $this->security->id]);

    expect(fn () => $this->deleter->delete($this->user, $holding))->toThrow(HoldingRefusedException::class)
        ->and($holding->fresh())->not->toBeNull()
        ->and($trade->fresh())->not->toBeNull();
});

it('refuses to delete another user\'s holding', function () {
    $stranger = Account::factory()->investment()->create();
    $theirs = Holding::factory()->create(['account_id' => $stranger->id, 'security_id' => $this->security->id]);

    expect(fn () => $this->deleter->delete($this->user, $theirs))->toThrow(HoldingRefusedException::class)
        ->and($theirs->fresh())->not->toBeNull();
});

it('leaves other accounts\' positions in the same security alone', function () {
    $mine = Account::factory()->investment()->for($this->user)->create();
    $other = Account::factory()->investment()->for($this->user)->create();
    $holding = Holding::factory()->create(['account_id' => $mine->id, 'security_id' => $this->security->id]);
    $otherHolding = Holding::factory()->create(['account_id' => $other->id, 'security_id' => $this->security->id]);

    $this->deleter->delete($this->user, $holding);

    expect($otherHolding->fresh())->not->toBeNull();
});
