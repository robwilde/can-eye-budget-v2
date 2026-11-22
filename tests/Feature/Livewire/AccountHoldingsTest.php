<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TradeActivity;
use App\Enums\TransactionDirection;
use App\Enums\TransactionStatus;
use App\Livewire\AccountHoldings;
use App\Livewire\AccountShow;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Holding;
use App\Models\Security;
use App\Models\SecurityPrice;
use App\Models\Trade;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Investments\Decimal;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    config(['budget.investments.enabled' => true]);
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->account = Account::factory()->investment()->for($this->user)->create();
    $this->security = Security::factory()->create(['ticker' => 'VAS', 'exchange_mic' => 'XASX', 'currency' => 'AUD']);
});

function holdings(mixed $test): Testable
{
    return Livewire::actingAs($test->user)->test(AccountHoldings::class, ['account' => $test->account]);
}

it('hides holdings when the investments flag is off', function () {
    config(['budget.investments.enabled' => false]);

    Livewire::actingAs($this->user)->test(AccountShow::class, ['account' => $this->account])
        ->set('tab', 'holdings')
        ->assertSet('tab', 'overview')
        ->assertDontSee('Holdings');

    Livewire::actingAs($this->user)->test(AccountHoldings::class, ['account' => $this->account])->assertNotFound();
});

it('shows the holdings tab on an investment account when the flag is on', function () {
    Livewire::actingAs($this->user)->test(AccountShow::class, ['account' => $this->account])
        ->set('tab', 'holdings')
        ->assertSet('tab', 'holdings')
        ->assertSeeLivewire(AccountHoldings::class);

    $savings = Account::factory()->savings()->for($this->user)->create();

    Livewire::actingAs($this->user)->test(AccountShow::class, ['account' => $savings])
        ->set('tab', 'holdings')
        ->assertSet('tab', 'overview');
});

it('records a trade from the holdings tab and audits it', function () {
    holdings($this)
        ->set('activity', 'buy')
        ->set('securityRef', 'id:'.$this->security->id)
        ->set('quantity', '10')
        ->set('price', '15')
        ->set('fee', '5')
        ->call('recordTrade')
        ->assertHasNoErrors()
        ->assertSee('Recorded.');

    $trade = Trade::query()->sole();

    expect($trade->amount)->toBe(15500)
        ->and(Decimal::trim($trade->quantity))->toBe('10')
        ->and(Holding::query()->sole()->quantity)->toBe($trade->quantity)
        ->and(AuditEvent::query()->where('action', 'livewire.account-holdings.recordTrade')->where('user_id', $this->user->id)->exists())->toBeTrue();
});

it('refuses a trade whose amount is not a number', function () {
    holdings($this)
        ->set('securityRef', 'id:'.$this->security->id)
        ->set('quantity', '10')
        ->set('price', 'abc')
        ->call('recordTrade')
        ->assertHasErrors('holdings');

    expect(Trade::query()->count())->toBe(0);
});

it('shows the holding with its value, an unknown cost as dashes and the statement figures', function () {
    SecurityPrice::factory()->create(['security_id' => $this->security->id, 'currency' => 'AUD', 'date' => '2026-03-30', 'price' => 1000]);
    SecurityPrice::factory()->create(['security_id' => $this->security->id, 'currency' => 'AUD', 'date' => '2026-03-31', 'price' => 1100]);
    Holding::factory()->create(['account_id' => $this->account->id, 'security_id' => $this->security->id, 'currency' => 'AUD', 'quantity' => '10', 'amount' => 11000, 'date' => '2026-03-31']);
    Transaction::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->account->id, 'amount' => 20000, 'direction' => TransactionDirection::Credit, 'status' => TransactionStatus::Posted]);

    $component = holdings($this)->assertSee('VAS')->assertSee('--');
    $statement = $component->instance()->statement;

    expect($statement['holdingsValue'])->toBe(11000)
        ->and($statement['dayChange'])->toBe(1000)
        ->and($statement['contributions'])->toBe(20000)
        ->and($statement['unrealised'])->toBeNull();
});

it('refuses to delete a provider-recreated holding with an alert', function () {
    $account = Account::factory()->investment()->withRedbark()->for($this->user)->create();
    $holding = Holding::factory()->create(['account_id' => $account->id, 'security_id' => $this->security->id]);

    Livewire::actingAs($this->user)->test(AccountHoldings::class, ['account' => $account])
        ->call('deleteHolding', $holding->id)
        ->assertHasErrors('holdings')
        ->assertSee('cannot be deleted');

    expect($holding->fresh())->not->toBeNull()
        ->and(AuditEvent::query()->where('action', 'livewire.account-holdings.deleteHolding')->sole()->outcome->value)->toBe('failure');
});

it('deletes a holding on a manual account', function () {
    $holding = Holding::factory()->create(['account_id' => $this->account->id, 'security_id' => $this->security->id]);

    holdings($this)->call('deleteHolding', $holding->id)->assertHasNoErrors();

    expect($holding->fresh())->toBeNull();
});

it('alerts when refreshing prices for an offline security', function () {
    config(['services.security_prices.enabled' => true, 'services.security_prices.api_key' => 'k']);
    Http::fake();
    $offline = Security::factory()->offline()->create(['ticker' => 'GONE']);
    Holding::factory()->create(['account_id' => $this->account->id, 'security_id' => $offline->id]);

    holdings($this)
        ->call('refreshPrices')
        ->assertHasErrors('holdings')
        ->assertSee('Prices were not refreshed for GONE');

    Http::assertNothingSent();
});

it('refuses changes to another user\'s holdings', function () {
    $other = Account::factory()->investment()->for(User::factory()->create())->create();
    $theirs = Holding::factory()->create(['account_id' => $other->id, 'security_id' => $this->security->id, 'cost_basis_per_share' => 500, 'cost_basis_source' => 'calculated']);

    holdings($this)
        ->call('setCostBasis', $theirs->id, '99')
        ->assertHasErrors('holdings')
        ->call('deleteHolding', $theirs->id)
        ->assertHasErrors('holdings');

    $fresh = $theirs->fresh();

    expect($fresh)->not->toBeNull()
        ->and($fresh->cost_basis_per_share)->toBe(500);
});

it('refuses a holding id from another account of the same user', function () {
    $second = Account::factory()->investment()->for($this->user)->create();
    $holding = Holding::factory()->create(['account_id' => $second->id, 'security_id' => $this->security->id]);

    holdings($this)->call('deleteHolding', $holding->id)->assertHasErrors('holdings');

    expect($holding->fresh())->not->toBeNull();
});

it('sets and unlocks a manual cost basis', function () {
    $holding = Holding::factory()->create(['account_id' => $this->account->id, 'security_id' => $this->security->id, 'quantity' => '10']);

    holdings($this)->call('setCostBasis', $holding->id, '1,000.50')->assertHasNoErrors();

    expect($holding->fresh()->cost_basis_locked)->toBeTrue();

    holdings($this)->call('unlockCostBasis', $holding->id)->assertHasNoErrors();

    expect($holding->fresh()->cost_basis_locked)->toBeFalse();
});

it('remaps a holding to a security chosen from the ticker search and resets it', function () {
    config(['services.security_prices.enabled' => true, 'services.security_prices.api_key' => 'k']);
    Http::fake(['*/symbol_search*' => Http::response(['status' => 'ok', 'data' => [['symbol' => 'VGS', 'instrument_name' => 'Vanguard Global', 'exchange' => 'ASX', 'mic_code' => 'XASX', 'currency' => 'AUD', 'instrument_type' => 'ETF']]])]);
    $holding = Holding::factory()->create(['account_id' => $this->account->id, 'security_id' => $this->security->id, 'quantity' => '10']);

    $component = holdings($this)->set('tickerQuery', 'VGS');
    $ref = 'new:'.collect($component->instance()->searchResults)->first()->key();
    $component->call('remap', $holding->id, $ref)->assertHasNoErrors();

    $target = Security::query()->where('ticker', 'VGS')->sole();

    expect($holding->fresh()->security_id)->toBe($target->id);

    holdings($this)->call('resetMapping', $holding->id)->assertHasNoErrors();

    expect($holding->fresh()->security_id)->toBe($this->security->id);
});

it('records a dividend and a deposit', function () {
    holdings($this)->set('activity', 'dividend')->set('securityRef', 'id:'.$this->security->id)->set('amount', '12.50')->call('recordTrade')->assertHasNoErrors();
    holdings($this)->set('activity', 'deposit')->set('amount', '100')->call('recordTrade')->assertHasNoErrors();

    expect(Trade::query()->where('activity', TradeActivity::Dividend)->sole()->amount)->toBe(-1250)
        ->and(Transaction::query()->where('account_id', $this->account->id)->sole()->amount)->toBe(10000);
});

it('offers securities the app already knows by ticker for a new account', function () {
    Security::factory()->create(['ticker' => 'ZZTOP', 'exchange_mic' => 'XASX']);

    $component = holdings($this);

    expect($component->instance()->knownSecurities->pluck('ticker')->all())->toBe([]);

    $component->set('tickerQuery', 'zzt');

    expect($component->instance()->knownSecurities->pluck('ticker')->all())->toBe(['ZZTOP']);
});

it('shows a refresh that fetched nothing as an alert, not as success', function () {
    $holding = Holding::factory()->create(['account_id' => $this->account->id, 'security_id' => $this->security->id]);

    holdings($this)->call('refreshPrices')->assertHasErrors('holdings')->assertDontSee('Prices refreshed.');

    expect($holding->fresh())->not->toBeNull();
});

it('shows a security whose last refresh returned no prices on its row', function () {
    $this->security->forceFill(['last_error' => 'no_prices'])->save();
    Holding::factory()->create(['account_id' => $this->account->id, 'security_id' => $this->security->id, 'quantity' => '1', 'amount' => 100]);

    holdings($this)->assertSeeHtml('data-test="holding-error"')->assertSee('no_prices');
});

it('shows the remap warning in the detail view', function () {
    $holding = Holding::factory()->create(['account_id' => $this->account->id, 'security_id' => $this->security->id, 'quantity' => '1', 'amount' => 100]);

    holdings($this)->set('detail', $holding->id)->assertSee('also moves any genuine trades already recorded on the target security');
});

it('records a fee from the amount field', function () {
    holdings($this)->set('activity', 'fee')->set('amount', '9.95')->call('recordTrade')->assertHasNoErrors();

    expect(Trade::query()->where('activity', TradeActivity::Fee)->sole()->amount)->toBe(995);
});
