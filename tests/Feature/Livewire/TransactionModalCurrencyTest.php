<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\ExchangeRateProviderContract;
use App\DTOs\ExchangeRateQuote;
use App\Enums\TransactionDirection;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo('2026-10-15');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create(['currency' => 'AUD']);
});

function currencyAddForm(User $user, Account $account): Testable
{
    return Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-10-12')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '25 hotel')
        ->set('accountId', $account->id);
}

function bindRateProvider(?float $rate): void
{
    app()->bind(ExchangeRateProviderContract::class, fn () => new class($rate) implements ExchangeRateProviderContract
    {
        public function __construct(private readonly ?float $rate) {}

        public function rate(string $from, string $to, CarbonInterface $date): ?ExchangeRateQuote
        {
            return $this->rate === null ? null : new ExchangeRateQuote($this->rate, CarbonImmutable::parse($date->toDateString()));
        }
    });
}

it('saves a transaction in another currency with its own exchange rate', function () {
    currencyAddForm($this->user, $this->account)
        ->set('currency', 'EUR')
        ->set('exchangeRate', '1.65')
        ->call('save')
        ->assertHasNoErrors();

    $transaction = Transaction::query()->where('user_id', $this->user->id)->sole();

    expect($transaction->currency)->toBe('EUR')
        ->and($transaction->exchange_rate)->toBe(1.65)
        ->and($transaction->amount)->toBe(2500);
});

it('stores the account currency when none is chosen', function () {
    currencyAddForm($this->user, $this->account)->call('save')->assertHasNoErrors();

    $transaction = Transaction::query()->where('user_id', $this->user->id)->sole();

    expect($transaction->currency)->toBe('AUD')
        ->and($transaction->exchange_rate)->toBeNull();
});

it('drops a typed rate when the currency is the account currency', function () {
    currencyAddForm($this->user, $this->account)
        ->set('currency', 'AUD')
        ->set('exchangeRate', '2')
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::query()->where('user_id', $this->user->id)->sole()->exchange_rate)->toBeNull();
});

it('rejects a per-transaction exchange rate of zero in the form', function (string $rate) {
    currencyAddForm($this->user, $this->account)
        ->set('currency', 'EUR')
        ->set('exchangeRate', $rate)
        ->call('save')
        ->assertHasErrors(['exchangeRate']);

    expect(Transaction::query()->count())->toBe(0);
})->with(['0', '-1.2']);

it('refuses a currency that is not allowed', function () {
    $user = User::factory()->withPreferences(['allowedCurrencies' => ['EUR']])->create();
    $account = Account::factory()->for($user)->create(['currency' => 'AUD']);

    currencyAddForm($user, $account)->set('currency', 'USD')->call('save')->assertHasErrors(['currency']);

    expect(Transaction::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('offers the currency select only for allowed currencies', function () {
    $user = User::factory()->withPreferences(['allowedCurrencies' => ['EUR']])->create();
    $account = Account::factory()->for($user)->create(['currency' => 'AUD']);

    $modal = currencyAddForm($user, $account);

    expect($modal->viewData('currencies'))->toBe(['AUD', 'EUR'])
        ->and($modal->viewData('accountCurrency'))->toBe('AUD');
    $modal->assertSeeHtml('data-testid="currency-select"');
});

it('shows the rate field only when the currency differs from the account currency', function () {
    $modal = currencyAddForm($this->user, $this->account);

    $modal->assertDontSeeHtml('data-testid="exchange-rate"')->set('currency', 'EUR')->assertSeeHtml('data-testid="exchange-rate"');
});

it('carries currency and rate to a new version edited through the modal', function () {
    $transaction = Transaction::factory()->for($this->user)->for($this->account)->manual()->create([
        'amount' => 2500,
        'currency' => 'EUR',
        'exchange_rate' => 1.65,
        'description' => 'hotel',
        'post_date' => '2026-10-10',
    ]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertSet('currency', 'EUR')
        ->assertSet('exchangeRate', '1.65')
        ->set('notes', 'late checkout')
        ->call('save')
        ->assertHasNoErrors();

    $current = Transaction::findCurrentVersion($transaction->id, $this->user->id);

    expect($current->id)->not->toBe($transaction->id)
        ->and($current->currency)->toBe('EUR')
        ->and($current->exchange_rate)->toBe(1.65)
        ->and($current->notes)->toBe('late checkout');
});

it('can change the currency of an existing transaction', function () {
    $transaction = Transaction::factory()->for($this->user)->for($this->account)->manual()->create(['currency' => 'EUR', 'exchange_rate' => 1.65]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('currency', 'AUD')
        ->call('save')
        ->assertHasNoErrors();

    $current = Transaction::findCurrentVersion($transaction->id, $this->user->id);

    expect($current->currency)->toBe('AUD')
        ->and($current->exchange_rate)->toBeNull();
});

it('still edits a transaction whose currency is no longer allowed', function () {
    $user = User::factory()->withPreferences(['allowedCurrencies' => ['AUD']])->create();
    $account = Account::factory()->for($user)->create(['currency' => 'AUD']);
    $transaction = Transaction::factory()->for($user)->for($account)->manual()->create(['currency' => 'EUR', 'exchange_rate' => 1.65]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('notes', 'kept')
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::findCurrentVersion($transaction->id, $user->id)->currency)->toBe('EUR');
});

it('hides the currency select on a bank feed row and keeps its currency', function () {
    $transaction = Transaction::factory()->for($this->user)->for($this->account)->fromRedbark()->create(['currency' => 'AUD']);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertDontSeeHtml('data-testid="currency-select"')
        ->set('notes', 'feed note')
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::findCurrentVersion($transaction->id, $this->user->id)->currency)->toBe('AUD');
});

it('shows the current rate for the currency, account and date', function () {
    bindRateProvider(null);
    ExchangeRate::factory()->create(['from_currency' => 'EUR', 'to_currency' => 'AUD', 'rate_date' => '2026-10-12', 'rate' => 1.65]);

    currencyAddForm($this->user, $this->account)
        ->set('currency', 'EUR')
        ->assertSeeHtml('data-testid="current-rate"')
        ->assertSee('1 EUR = 1.65 AUD');
});

it('asks the provider for a rate that is not cached', function () {
    bindRateProvider(1.7);

    currencyAddForm($this->user, $this->account)->set('currency', 'EUR')->assertSee('1 EUR = 1.7 AUD');
});

it('says so when no rate is available', function () {
    bindRateProvider(null);

    currencyAddForm($this->user, $this->account)->set('currency', 'EUR')->assertSee('No rate is available for this date.');
});

it('says same currency when the currency matches the account', function () {
    currencyAddForm($this->user, $this->account)->assertSeeHtml('data-testid="same-currency"')->set('currency', 'AUD')->assertSeeHtml('data-testid="same-currency"');
});

it('still saves when the rate provider is disabled and no rate is known', function () {
    currencyAddForm($this->user, $this->account)
        ->set('currency', 'EUR')
        ->assertSuccessful()
        ->assertSee('No rate is available for this date.')
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::query()->where('user_id', $this->user->id)->sole()->currency)->toBe('EUR');
});

it('makes an edit that moves the account follow the new account currency', function () {
    $euro = Account::factory()->for($this->user)->create(['currency' => 'EUR']);
    $transaction = Transaction::factory()->for($this->user)->for($this->account)->manual()->create(['description' => 'hotel', 'post_date' => '2026-10-10']);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertSet('currency', '')
        ->set('accountId', $euro->id)
        ->call('save')
        ->assertHasNoErrors();

    $current = Transaction::findCurrentVersion($transaction->id, $this->user->id);

    expect($current->account_id)->toBe($euro->id)
        ->and($current->currency)->toBe('EUR')
        ->and($current->exchange_rate)->toBeNull();
});

it('keeps a currency the user picked when the edit also moves the account', function () {
    $euro = Account::factory()->for($this->user)->create(['currency' => 'EUR']);
    $transaction = Transaction::factory()->for($this->user)->for($this->account)->manual()->create(['description' => 'hotel', 'post_date' => '2026-10-10']);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('accountId', $euro->id)
        ->set('currency', 'USD')
        ->set('exchangeRate', '1.4')
        ->call('save')
        ->assertHasNoErrors();

    $current = Transaction::findCurrentVersion($transaction->id, $this->user->id);

    expect($current->currency)->toBe('USD')
        ->and($current->exchange_rate)->toBe(1.4);
});

it('clears a stale rate when the picked currency equals the new account currency', function () {
    $euro = Account::factory()->for($this->user)->create(['currency' => 'EUR']);
    $transaction = Transaction::factory()->for($this->user)->for($this->account)->manual()->create(['currency' => 'USD', 'exchange_rate' => 1.4, 'description' => 'hotel', 'post_date' => '2026-10-10']);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('accountId', $euro->id)
        ->set('currency', 'EUR')
        ->call('save')
        ->assertHasNoErrors();

    $current = Transaction::findCurrentVersion($transaction->id, $this->user->id);

    expect($current->currency)->toBe('EUR')
        ->and($current->exchange_rate)->toBeNull();
});

it('keeps the denomination when a foreign currency transaction is copied', function () {
    $source = Transaction::factory()->for($this->user)->for($this->account)->manual()->create([
        'amount' => 2500,
        'currency' => 'EUR',
        'exchange_rate' => 1.65,
    ]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('copy-transaction', id: $source->id)
        ->assertSet('currency', 'EUR')
        ->assertSet('exchangeRate', '1.65')
        ->call('save')
        ->assertHasNoErrors();

    $copy = Transaction::query()->where('user_id', $this->user->id)->whereKeyNot($source->id)->sole();

    expect($copy->currency)->toBe('EUR')
        ->and($copy->exchange_rate)->toBe(1.65);
});

it('ignores a hidden zero rate after switching back to the account currency', function () {
    currencyAddForm($this->user, $this->account)
        ->set('currency', 'EUR')
        ->set('exchangeRate', '0')
        ->set('currency', 'AUD')
        ->call('save')
        ->assertHasNoErrors();

    $transaction = Transaction::query()->where('user_id', $this->user->id)->sole();

    expect($transaction->currency)->toBe('AUD')
        ->and($transaction->exchange_rate)->toBeNull();
});

it('selects the empty option when editing a transaction in the account currency', function () {
    $transaction = Transaction::factory()->for($this->user)->for($this->account)->manual()->create(['currency' => 'AUD']);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertSet('currency', '');
});

function currencyTransferPair(User $user, Account $from, Account $to): Transaction
{
    $debit = Transaction::factory()->for($user)->for($from)->manual()->create(['amount' => 10000, 'direction' => TransactionDirection::Debit, 'description' => 'savings transfer', 'post_date' => '2026-10-12']);
    $credit = Transaction::factory()->for($user)->for($to)->manual()->create(['amount' => 10000, 'direction' => TransactionDirection::Credit, 'description' => 'savings transfer', 'post_date' => '2026-10-12', 'transfer_pair_id' => $debit->id]);
    $debit->update(['transfer_pair_id' => $credit->id]);

    return $debit;
}

it('does not offer currency fields when a transfer is turned into an expense', function () {
    $other = Account::factory()->for($this->user)->create(['currency' => 'AUD']);
    $debit = currencyTransferPair($this->user, $this->account, $other);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('transactionType', 'expense')
        ->assertDontSeeHtml('data-testid="currency-fields"');
});

it('ignores a leftover invalid rate when the type switches to transfer', function () {
    $other = Account::factory()->for($this->user)->create(['currency' => 'AUD']);

    currencyAddForm($this->user, $this->account)
        ->set('currency', 'EUR')
        ->set('exchangeRate', '0')
        ->set('transactionType', 'transfer')
        ->set('transferToAccountId', $other->id)
        ->call('save')
        ->assertHasNoErrors(['exchangeRate']);
});

it('rejects a rate the model would refuse', function (string $rate) {
    currencyAddForm($this->user, $this->account)
        ->set('currency', 'EUR')
        ->set('exchangeRate', $rate)
        ->call('save')
        ->assertHasErrors(['exchangeRate']);

    expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(0);
})->with(['rounds to zero' => '0.000000001', 'too large' => '10000000000']);

it('drops the rate when an edit moves a foreign currency row to an account in another currency', function () {
    $euro = Account::factory()->for($this->user)->create(['currency' => 'EUR']);
    $transaction = Transaction::factory()->for($this->user)->for($this->account)->manual()->create(['description' => 'hotel', 'post_date' => '2026-10-10', 'currency' => 'USD', 'exchange_rate' => 1.4]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('accountId', $euro->id)
        ->call('save')
        ->assertHasNoErrors();

    $child = Transaction::query()->where('parent_transaction_id', $transaction->id)->sole();

    expect($child->currency)->toBe('USD')
        ->and($child->exchange_rate)->toBeNull();
});

it('keeps the currency select on the current value after moving to an account in that currency', function () {
    $euro = Account::factory()->for($this->user)->create(['currency' => 'EUR']);

    currencyAddForm($this->user, $this->account)
        ->set('currency', 'EUR')
        ->set('accountId', $euro->id)
        ->assertSeeHtml('wire:key="EUR"');
});

it('asks the provider only once for repeated renders of the same pair and date', function () {
    $calls = 0;
    app()->bind(ExchangeRateProviderContract::class, function () use (&$calls) {
        return new class(function () use (&$calls): void {
            $calls++;
        }) implements ExchangeRateProviderContract
        {
            public function __construct(private readonly Closure $onCall) {}

            public function rate(string $from, string $to, CarbonInterface $date): ?ExchangeRateQuote
            {
                ($this->onCall)();

                return null;
            }
        };
    });

    currencyAddForm($this->user, $this->account)
        ->set('currency', 'EUR')
        ->set('notes', 'one')
        ->set('notes', 'two');

    expect($calls)->toBe(1);
});

it('does not ask the provider about a currency the form does not offer', function () {
    $calls = 0;
    app()->bind(ExchangeRateProviderContract::class, function () use (&$calls) {
        return new class(function () use (&$calls): void {
            $calls++;
        }) implements ExchangeRateProviderContract
        {
            public function __construct(private readonly Closure $onCall) {}

            public function rate(string $from, string $to, CarbonInterface $date): ?ExchangeRateQuote
            {
                ($this->onCall)();

                return null;
            }
        };
    });

    $this->user->forceFill(['base_currency' => 'AUD'])->save();
    $this->user->forceFill(['preferences' => ['allowedCurrencies' => ['AUD', 'EUR']]])->save();

    currencyAddForm($this->user, $this->account)->set('currency', 'XYZ');

    expect($calls)->toBe(0);
});

it('looks a future date up as today', function () {
    $dates = [];
    app()->bind(ExchangeRateProviderContract::class, function () use (&$dates) {
        return new class(function (string $date) use (&$dates): void {
            $dates[] = $date;
        }) implements ExchangeRateProviderContract
        {
            public function __construct(private readonly Closure $onCall) {}

            public function rate(string $from, string $to, CarbonInterface $date): ?ExchangeRateQuote
            {
                ($this->onCall)($date->toDateString());

                return null;
            }
        };
    });

    currencyAddForm($this->user, $this->account)->set('currency', 'EUR')->set('date', '2031-01-01');

    expect(array_values(array_unique($dates)))->toBe(['2026-10-12', '2026-10-15']);
});
