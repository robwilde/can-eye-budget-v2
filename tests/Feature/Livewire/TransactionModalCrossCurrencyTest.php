<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\ExchangeRateProviderContract;
use App\DTOs\ExchangeRateQuote;
use App\DTOs\NewTransfer;
use App\Enums\AuditOutcome;
use App\Enums\ImportSource;
use App\Enums\TransactionDirection;
use App\Enums\TransferLinkSource;
use App\Exceptions\ExchangeRateUnavailableException;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\AccountValuation;
use App\Models\AuditEvent;
use App\Models\ExchangeRate;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use App\Services\Transfers\TransferLinker;
use App\Services\UserCategoryProvisioner;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Ramsey\Uuid\Uuid;

beforeEach(function (): void {
    $this->travelTo('2026-10-15');
    $this->user = User::factory()->create(['base_currency' => 'AUD']);
    app(UserCategoryProvisioner::class)->provision($this->user);
    $this->usd = fxAccount($this->user, 'USD');
    $this->eur = fxAccount($this->user, 'EUR');
});

function fxAccount(User $user, string $currency, int $opening = 500000): Account
{
    $account = Account::factory()->for($user)->create([
        'currency' => $currency,
        'balance' => 0,
        'balance_source' => ImportSource::Manual,
        'is_tracked' => true,
    ]);
    AccountValuation::factory()->for($account)->opening()->create(['valued_on' => '2026-10-01', 'balance' => $opening]);
    app(AccountBalanceHistoryBuilder::class)->rebuild($account);

    return $account;
}

function fxRate(string $from, string $to, float $rate, string $date = '2026-10-10'): void
{
    ExchangeRate::factory()->create(['from_currency' => $from, 'to_currency' => $to, 'rate' => $rate, 'rate_date' => $date]);
}

function fxViaBase(string $from, string $to, string $date = '2026-10-10'): void
{
    fxRate($from, 'AUD', 1.5, $date);
    fxRate('AUD', $to, 0.6, $date);
}

function fxFeedRow(User $user, Account $account, int $cents, string $date = '2026-10-10'): Transaction
{
    return Transaction::factory()->for($user)->fromRedbark()->create([
        'account_id' => $account->id,
        'direction' => $cents < 0 ? TransactionDirection::Debit : TransactionDirection::Credit,
        'amount' => $cents,
        'post_date' => $date,
        'description' => 'Transfer',
    ]);
}

function fxMissingLegForm(User $user, Transaction $row, Account $to): Testable
{
    return Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)
        ->set('transactionType', 'transfer')
        ->set('transferToAccountId', $to->id);
}

function fxTransferForm(User $user, Account $from, Account $to, string $input = '100 abroad', string $rate = '', string $sourceFee = '', string $destinationFee = ''): Testable
{
    return Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-10-10')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', $input)
        ->set('accountId', $from->id)
        ->set('transferToAccountId', $to->id)
        ->set('exchangeRate', $rate)
        ->set('sourceFee', $sourceFee)
        ->set('destinationFee', $destinationFee);
}

function fxLegs(User $user, Account $from): array
{
    $debit = Transaction::query()->where('user_id', $user->id)->where('account_id', $from->id)->whereNotNull('transfer_pair_id')->sole();

    return [$debit, $debit->transferPair];
}

it('converts the incoming leg of a cross-currency transfer', function () {
    fxTransferForm($this->user, $this->usd, $this->eur, '100 abroad', rate: '0.90')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    [$debit, $credit] = fxLegs($this->user, $this->usd);

    expect($debit->amount)->toBe(10000)
        ->and($debit->direction)->toBe(TransactionDirection::Debit)
        ->and($debit->currency)->toBe('USD')
        ->and($credit->amount)->toBe(9000)
        ->and($credit->direction)->toBe(TransactionDirection::Credit)
        ->and($credit->account_id)->toBe($this->eur->id)
        ->and($credit->currency)->toBe('EUR')
        ->and($credit->transfer_pair_id)->toBe($debit->id)
        ->and($debit->exchange_rate)->toBeNull()
        ->and($credit->exchange_rate)->toBeNull();
});

it('converts at the stored rate when the source is the base currency', function () {
    fxRate('USD', 'AUD', 1.5);
    $aud = fxAccount($this->user, 'AUD');

    fxTransferForm($this->user, $this->usd, $aud)->call('save')->assertHasNoErrors();

    expect(fxLegs($this->user, $this->usd)[1]->amount)->toBe(15000);
});

it('converts at the inverse of the stored rate when the destination is the base currency', function () {
    fxRate('USD', 'AUD', 1.5);
    $aud = fxAccount($this->user, 'AUD');

    fxTransferForm($this->user, $aud, $this->usd)->call('save')->assertHasNoErrors();

    expect(fxLegs($this->user, $aud)[1]->amount)->toBe(6667);
});

it('derives the rate through the base currency for two non-base currencies', function () {
    fxRate('USD', 'AUD', 1.5);
    fxRate('AUD', 'EUR', 0.6);

    fxTransferForm($this->user, $this->usd, $this->eur)->call('save')->assertHasNoErrors();

    expect(fxLegs($this->user, $this->usd)[1]->amount)->toBe(9000);
});

it('derives the rate from inverse rates when only those are stored', function () {
    fxRate('AUD', 'USD', 1 / 1.5);
    fxRate('EUR', 'AUD', 1 / 0.6);

    fxTransferForm($this->user, $this->usd, $this->eur)->call('save')->assertHasNoErrors();

    expect(fxLegs($this->user, $this->usd)[1]->amount)->toBe(9000);
});

it('rejects a cross-currency transfer with no custom rate and no known rate', function () {
    fxTransferForm($this->user, $this->usd, $this->eur)
        ->call('save')
        ->assertHasErrors(['exchangeRate']);

    expect(Transaction::withTrashed()->count())->toBe(0);
});

it('rejects a zero or negative custom rate', function (string $rate) {
    fxTransferForm($this->user, $this->usd, $this->eur, '100 abroad', rate: $rate)
        ->call('save')
        ->assertHasErrors(['exchangeRate']);

    expect(Transaction::withTrashed()->count())->toBe(0);
})->with(['zero' => '0', 'negative' => '-0.9']);

it('shows the current rate, or says it is unavailable', function () {
    $form = fxTransferForm($this->user, $this->usd, $this->eur)->assertSee('unavailable');

    fxViaBase('USD', 'EUR');
    Cache::flush();

    fxTransferForm($this->user, $this->usd, $this->eur)
        ->assertSeeHtml('data-testid="transfer-exchange-rate"')
        ->assertSee('1 USD = 0.9 EUR');

    $form->assertSeeHtml('data-testid="transfer-fx"');

    fxTransferForm($this->user, $this->usd, fxAccount($this->user, 'USD'))->assertDontSeeHtml('data-testid="transfer-fx"');
});

it('ignores a typed rate when both accounts share a currency', function () {
    $other = fxAccount($this->user, 'USD');

    fxTransferForm($this->user, $this->usd, $other, '100 same', rate: '0.5')->call('save')->assertHasNoErrors();

    expect(fxLegs($this->user, $this->usd)[1]->amount)->toBe(10000);
});

it('keeps fees working in each accounts own currency', function () {
    fxTransferForm($this->user, $this->usd, $this->eur, '100 abroad', rate: '0.90', sourceFee: '1', destinationFee: '0.50')
        ->call('save')
        ->assertHasNoErrors();

    $fees = Transaction::query()->where('user_id', $this->user->id)->whereNotNull('transfer_fee_for_id')->orderBy('id')->get();

    expect($fees)->toHaveCount(2)
        ->and($fees[0]->account_id)->toBe($this->usd->id)
        ->and($fees[0]->currency)->toBe('USD')
        ->and($fees[0]->amount)->toBe(100)
        ->and($fees[1]->account_id)->toBe($this->eur->id)
        ->and($fees[1]->currency)->toBe('EUR')
        ->and($fees[1]->amount)->toBe(50);
});

it('returns the first transfer for a repeat but refuses a changed rate under the same key', function () {
    Str::createUuidsUsing(fn () => Uuid::fromString('44444444-4444-4444-8444-444444444444'));

    try {
        fxTransferForm($this->user, $this->usd, $this->eur, '100 abroad', rate: '0.90')->call('save')->assertHasNoErrors();
        fxTransferForm($this->user, $this->usd, $this->eur, '100 abroad', rate: '0.90')->call('save')->assertHasNoErrors();
        fxTransferForm($this->user, $this->usd, $this->eur, '100 abroad', rate: '0.80')->call('save')->assertHasErrors(['descriptionInput']);
    } finally {
        Str::createUuidsNormally();
    }

    expect(Transaction::withTrashed()->where('user_id', $this->user->id)->count())->toBe(2);
});

it('cannot create a cross-currency transfer into another users account', function () {
    $stranger = User::factory()->create();
    $foreign = fxAccount($stranger, 'EUR');

    fxTransferForm($this->user, $this->usd, $foreign, '100 abroad', rate: '0.90')
        ->call('save')
        ->assertHasErrors(['transferToAccountId']);

    expect(fn () => app(TransferLinker::class)->create(new NewTransfer(
        userId: $this->user->id,
        fromAccountId: $this->usd->id,
        toAccountId: $foreign->id,
        amount: 10000,
        description: 'abroad',
        postDate: '2026-10-10',
        exchangeRate: 0.9,
    )))->toThrow(InvalidArgumentException::class);

    expect(Transaction::withTrashed()->count())->toBe(0);
});

it('records an audit event for a cross-currency transfer', function () {
    fxTransferForm($this->user, $this->usd, $this->eur, '100 abroad', rate: '0.90')->call('save');

    $event = AuditEvent::query()->where('action', 'livewire.transaction-modal.save')->sole();

    expect($event->user_id)->toBe($this->user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success);
});

it('keeps balance history consistent after a cross-currency transfer', function () {
    fxTransferForm($this->user, $this->usd, $this->eur, '100 abroad', rate: '0.90', sourceFee: '1', destinationFee: '0.50')->call('save');

    expectBalanceHistoryConsistent($this->usd, $this->eur);
    expect($this->usd->dailyBalances()->whereDate('balance_date', '2026-10-10')->sole()->end_balance)->toBe(500000 - 10000 - 100)
        ->and($this->eur->dailyBalances()->whereDate('balance_date', '2026-10-10')->sole()->end_balance)->toBe(500000 + 9000 - 50);

    [$debit] = fxLegs($this->user, $this->usd);
    app(TransferLinker::class)->unlink($debit);

    expectBalanceHistoryConsistent($this->usd, $this->eur);
});

it('keeps the incoming amount when a cross-currency transfer is edited without changing the amount', function () {
    fxTransferForm($this->user, $this->usd, $this->eur, '100 abroad', rate: '0.90')->call('save');
    [$debit] = fxLegs($this->user, $this->usd);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('notes', 'renamed')
        ->call('save')
        ->assertHasNoErrors();

    $current = Transaction::query()->where('user_id', $this->user->id)->current()->whereNotNull('transfer_pair_id')->orderBy('direction')->get();

    expect($current->pluck('amount')->sort()->values()->all())->toBe([9000, 10000]);
    expectBalanceHistoryConsistent($this->usd, $this->eur);
});

it('offers a cross-currency candidate within the tolerance in the manual match list', function () {
    fxViaBase('USD', 'EUR');
    $out = Transaction::factory()->for($this->user)->fromRedbark()->create([
        'account_id' => $this->usd->id, 'direction' => TransactionDirection::Debit, 'amount' => -10000, 'post_date' => '2026-10-10',
    ]);
    $near = Transaction::factory()->for($this->user)->fromRedbark()->create([
        'account_id' => $this->eur->id, 'direction' => TransactionDirection::Credit, 'amount' => 9300, 'post_date' => '2026-10-25',
    ]);
    Transaction::factory()->for($this->user)->fromRedbark()->create([
        'account_id' => $this->eur->id, 'direction' => TransactionDirection::Credit, 'amount' => 20000, 'post_date' => '2026-10-11',
    ]);

    $candidates = Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $out->id)
        ->set('transactionType', 'transfer')
        ->instance()
        ->transferCandidates();

    expect($candidates->pluck('id')->all())->toBe([$near->id]);
});

function fxPlannedTransfer(User $user, Account $from, Account $to): PlannedTransaction
{
    return PlannedTransaction::factory()->for($user)->for($from)->monthly()->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 5000,
        'description' => 'abroad plan',
        'start_date' => '2026-05-01',
        'transfer_to_account_id' => $to->id,
    ]);
}

it('shows an exchange rate error instead of failing when a realised planned transfer has no rate', function () {
    $planned = fxPlannedTransfer($this->user, $this->usd, $this->eur);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id, occurrenceDate: '2026-10-01')
        ->call('save')
        ->assertHasErrors(['exchangeRate']);

    expect(Transaction::withTrashed()->count())->toBe(0)
        ->and($planned->fresh())->not->toBeNull();
});

it('shows an exchange rate error instead of failing when a planned transfer is entered without a rate', function () {
    $planned = fxPlannedTransfer($this->user, $this->usd, $this->eur);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id)
        ->set('mode', 'enter')
        ->call('save')
        ->assertHasErrors(['exchangeRate']);

    expect(Transaction::withTrashed()->count())->toBe(0)
        ->and($planned->fresh())->not->toBeNull();
});

it('offers the rate field and honours a typed rate when realising a planned occurrence', function () {
    $planned = fxPlannedTransfer($this->user, $this->usd, $this->eur);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id, occurrenceDate: '2026-10-01')
        ->assertSeeHtml('data-testid="transfer-exchange-rate"')
        ->set('exchangeRate', '0.90')
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::query()->where('user_id', $this->user->id)->where('account_id', $this->eur->id)->sole()->amount)->toBe(4500);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id, occurrenceDate: '2026-11-01')
        ->assertDontSeeHtml('data-testid="transfer-exchange-rate"');
});

it('renders the transfer preview when the date is malformed', function () {
    fxTransferForm($this->user, $this->usd, $this->eur)
        ->set('date', 'not-a-date')
        ->assertSeeHtml('data-testid="transfer-exchange-rate"');
});

it('keeps the looked-up transfer rate, a miss included, for the cache lifetime', function () {
    $form = fxTransferForm($this->user, $this->usd, $this->eur)->assertSee('unavailable');

    fxViaBase('USD', 'EUR');

    $form->set('notes', 'again')->assertSee('unavailable');

    $this->travel(6)->minutes();

    $form->set('notes', 'once more')->assertSee('1 USD = 0.9 EUR');
});

it('does not look up a rate for the preview while a rate is typed', function () {
    fxViaBase('USD', 'EUR');
    $key = 'transaction-modal-transfer-rate:USD:EUR:AUD:2026-10-10';

    $form = fxTransferForm($this->user, $this->usd, $this->eur);

    expect(Cache::has($key))->toBeTrue();

    Cache::flush();

    $form->set('exchangeRate', '0.8')->assertDontSeeHtml('data-testid="transfer-current-rate"');

    expect(Cache::has($key))->toBeFalse();
});

it('converts a missing leg created on an account in another currency', function () {
    fxViaBase('USD', 'EUR');
    $feed = fxFeedRow($this->user, $this->usd, -10000);
    app(AccountBalanceHistoryBuilder::class)->rebuild($this->usd);

    fxMissingLegForm($this->user, $feed, $this->eur)->call('save')->assertHasNoErrors();

    $leg = Transaction::query()->where('account_id', $this->eur->id)->sole();

    expect($leg->amount)->toBe(9000)
        ->and($leg->currency)->toBe('EUR')
        ->and($leg->direction)->toBe(TransactionDirection::Credit)
        ->and($leg->transfer_pair_id)->toBe($feed->id)
        ->and($feed->fresh()->transfer_pair_id)->toBe($leg->id)
        ->and($this->eur->dailyBalances()->whereDate('balance_date', '2026-10-10')->sole()->end_balance)->toBe(500000 + 9000);
    expectBalanceHistoryConsistent($this->usd, $this->eur);
});

it('refuses to create a missing leg in another currency when no rate exists', function () {
    $feed = fxFeedRow($this->user, $this->usd, -10000);
    app(AccountBalanceHistoryBuilder::class)->rebuild($this->usd);

    fxMissingLegForm($this->user, $feed, $this->eur)
        ->call('save')
        ->assertHasErrors(['transferToAccountId']);

    expect(Transaction::query()->count())->toBe(1)
        ->and($feed->fresh()->transfer_pair_id)->toBeNull();
    expectBalanceHistoryConsistent($this->usd, $this->eur);
});

it('converts the mirror leg created on an untracked account in another currency', function () {
    fxViaBase('USD', 'EUR');
    $hidden = Account::factory()->for($this->user)->untracked()->create(['currency' => 'EUR']);
    $feed = fxFeedRow($this->user, $this->usd, -10000);

    $mirror = app(TransferLinker::class)->linkToUntrackedAccount($feed, $hidden, TransferLinkSource::Manual);

    expect($mirror->amount)->toBe(9000)
        ->and($mirror->currency)->toBe('EUR')
        ->and($mirror->transfer_pair_id)->toBe($feed->id);
});

it('refuses to create a mirror leg in another currency when no rate exists', function () {
    $hidden = Account::factory()->for($this->user)->untracked()->create(['currency' => 'EUR']);
    $feed = fxFeedRow($this->user, $this->usd, -10000);

    expect(fn () => app(TransferLinker::class)->linkToUntrackedAccount($feed, $hidden, TransferLinkSource::Manual))
        ->toThrow(ExchangeRateUnavailableException::class)
        ->and(Transaction::query()->count())->toBe(1)
        ->and($feed->fresh()->transfer_pair_id)->toBeNull();
});

function fxManualRow(User $user, Account $account, array $attributes = []): Transaction
{
    return Transaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'direction' => TransactionDirection::Debit,
        'amount' => 10000,
        'description' => 'abroad spend',
        'post_date' => '2026-10-10',
    ] + $attributes);
}

it('discards a typed rate when the From and To accounts are swapped', function () {
    fxViaBase('USD', 'EUR');

    fxTransferForm($this->user, $this->usd, $this->eur, '100 abroad', rate: '0.90')
        ->call('swapTransferAccounts')
        ->assertSet('exchangeRate', '')
        ->call('save')
        ->assertHasNoErrors();

    [$debit, $credit] = fxLegs($this->user, $this->eur);

    expect($debit->currency)->toBe('EUR')
        ->and($debit->amount)->toBe(10000)
        ->and($credit->currency)->toBe('USD')
        ->and($credit->amount)->toBe(11111);
});

it('discards a typed rate when either account changes', function () {
    $gbp = fxAccount($this->user, 'GBP');

    fxTransferForm($this->user, $this->usd, $this->eur, '100 abroad', rate: '0.90')
        ->set('transferToAccountId', $gbp->id)
        ->assertSet('exchangeRate', '');

    fxTransferForm($this->user, $this->usd, $this->eur, '100 abroad', rate: '0.90')
        ->set('accountId', $gbp->id)
        ->assertSet('exchangeRate', '');
});

it('does not carry the stored rate of a row into a transfer', function () {
    $row = fxManualRow($this->user, $this->usd, ['currency' => 'EUR', 'exchange_rate' => 1.1]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)
        ->assertSet('exchangeRate', '1.1')
        ->set('transactionType', 'transfer')
        ->assertSet('exchangeRate', '');
});

it('refuses to turn a row in another currency than its account into a transfer', function () {
    $row = fxManualRow($this->user, $this->usd, ['currency' => 'EUR', 'exchange_rate' => 1.1]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '100 abroad')
        ->set('transferToAccountId', $this->eur->id)
        ->set('exchangeRate', '0.90')
        ->call('save')
        ->assertHasErrors(['transactionType']);

    expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

it('converts a row in its own accounts currency into a cross-currency transfer', function () {
    $row = fxManualRow($this->user, $this->usd);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '100 abroad')
        ->set('transferToAccountId', $this->eur->id)
        ->set('exchangeRate', '0.90')
        ->call('save')
        ->assertHasNoErrors();

    $credit = Transaction::query()->where('account_id', $this->eur->id)->sole();
    $debit = $credit->transferPair;

    expect($credit->amount)->toBe(9000)
        ->and($credit->currency)->toBe('EUR')
        ->and($credit->exchange_rate)->toBeNull()
        ->and($debit->amount)->toBe(10000)
        ->and($debit->currency)->toBe('USD')
        ->and($debit->exchange_rate)->toBeNull();
});

it('writes nothing when a row is converted to a cross-currency transfer with no rate', function () {
    $row = fxManualRow($this->user, $this->usd);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '100 abroad')
        ->set('transferToAccountId', $this->eur->id)
        ->call('save')
        ->assertHasErrors(['exchangeRate']);

    expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

it('converts the incoming leg again when the amount of a cross-currency transfer is edited', function () {
    fxTransferForm($this->user, $this->usd, $this->eur, '100 abroad', rate: '0.90')->call('save');
    [$debit] = fxLegs($this->user, $this->usd);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('descriptionInput', '200 abroad')
        ->call('save')
        ->assertHasErrors(['exchangeRate']);

    expect(Transaction::query()->where('user_id', $this->user->id)->current()->whereNotNull('transfer_pair_id')->pluck('amount')->sort()->values()->all())->toBe([9000, 10000]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('descriptionInput', '200 abroad')
        ->set('exchangeRate', '0.90')
        ->call('save')
        ->assertHasNoErrors();

    $current = Transaction::query()->where('user_id', $this->user->id)->current()->whereNotNull('transfer_pair_id')->get();

    expect($current->pluck('amount')->sort()->values()->all())->toBe([18000, 20000])
        ->and($current->firstWhere('account_id', $this->eur->id)->currency)->toBe('EUR');
    expectBalanceHistoryConsistent($this->usd, $this->eur);
});

it('says the converted amount rounds to zero instead of claiming no rate exists', function () {
    fxTransferForm($this->user, $this->usd, $this->eur, '0.01 abroad', rate: '0.001')
        ->call('save')
        ->assertHasErrors(['exchangeRate']);

    expect(Transaction::withTrashed()->count())->toBe(0);

    fxTransferForm($this->user, $this->usd, $this->eur, '0.01 abroad', rate: '0.001')
        ->call('save')
        ->assertSee('rounds to zero');
});

it('converts a transfer dated in the future at the latest rate', function () {
    fxViaBase('USD', 'EUR', '2026-10-15');

    fxTransferForm($this->user, $this->usd, $this->eur)
        ->set('date', '2026-12-01')
        ->call('save')
        ->assertHasNoErrors();

    expect(fxLegs($this->user, $this->usd)[1]->amount)->toBe(9000);
});

it('hides the hidden accounts and refuses a mirror when a converted opposite row exists', function () {
    fxViaBase('USD', 'EUR');
    $hidden = Account::factory()->for($this->user)->untracked()->create(['currency' => 'EUR', 'name' => 'Vault']);
    $feed = fxFeedRow($this->user, $this->usd, -10000);
    fxFeedRow($this->user, $this->eur, 9000);

    fxMissingLegForm($this->user, $feed, $this->eur)
        ->assertDontSee('Hidden (untracked)')
        ->set('transferToAccountId', $hidden->id)
        ->call('save')
        ->assertHasErrors(['transferToAccountId']);

    expect(Transaction::query()->where('account_id', $hidden->id)->count())->toBe(0);
});

function fxRecordProviderLevels(ArrayObject $levels): void
{
    app()->bind(ExchangeRateProviderContract::class, fn () => new class($levels) implements ExchangeRateProviderContract
    {
        public function __construct(private readonly ArrayObject $levels) {}

        public function rate(string $from, string $to, CarbonInterface $date): ?ExchangeRateQuote
        {
            $this->levels[] = DB::transactionLevel();
            $rate = match ($from.'>'.$to) {
                'USD>AUD' => 1.5,
                'AUD>EUR' => 0.6,
                default => null,
            };

            return $rate === null ? null : new ExchangeRateQuote($rate, CarbonImmutable::parse($date->toDateString()));
        }
    });
}

it('looks the rate up before the mirror leg transaction opens', function () {
    $levels = new ArrayObject;
    fxRecordProviderLevels($levels);
    $hidden = Account::factory()->for($this->user)->untracked()->create(['currency' => 'EUR']);
    $feed = fxFeedRow($this->user, $this->usd, -10000);
    $baseline = DB::transactionLevel();

    $mirror = app(TransferLinker::class)->linkToUntrackedAccount($feed, $hidden, TransferLinkSource::Manual);

    expect($levels->getArrayCopy())->not->toBeEmpty()
        ->each->toBe($baseline)
        ->and($mirror->amount)->toBe(9000);
});

it('looks the rate up before the missing leg transaction opens', function () {
    $levels = new ArrayObject;
    fxRecordProviderLevels($levels);
    $feed = fxFeedRow($this->user, $this->usd, -10000);
    $baseline = DB::transactionLevel();

    $leg = app(TransferLinker::class)->createMissingLeg($feed, $this->eur, TransferLinkSource::Manual);

    expect($levels->getArrayCopy())->not->toBeEmpty()
        ->each->toBe($baseline)
        ->and($leg->amount)->toBe(9000);
});

it('keeps provider calls for other candidate currencies out of the locked recheck', function () {
    $levels = new ArrayObject;
    fxRecordProviderLevels($levels);
    $hidden = Account::factory()->for($this->user)->untracked()->create(['currency' => 'EUR']);
    $gbp = fxAccount($this->user, 'GBP');
    $feed = fxFeedRow($this->user, $this->usd, -10000);
    fxFeedRow($this->user, $gbp, 8000);
    $baseline = DB::transactionLevel();

    $mirror = app(TransferLinker::class)->linkToUntrackedAccount($feed, $hidden, TransferLinkSource::Manual);

    expect($levels->getArrayCopy())->not->toBeEmpty()
        ->each->toBe($baseline)
        ->and($mirror->amount)->toBe(9000);
});

it('returns the first transfer for a repeat of the same key even when its rate is no longer known', function () {
    fxViaBase('USD', 'EUR');
    $transfer = new NewTransfer(
        userId: $this->user->id,
        fromAccountId: $this->usd->id,
        toAccountId: $this->eur->id,
        amount: 10000,
        description: 'abroad',
        postDate: '2026-10-10',
    );

    $first = app(TransferLinker::class)->create($transfer, 'retry-key');
    ExchangeRate::query()->delete();

    expect(app(TransferLinker::class)->create($transfer, 'retry-key')->id)->toBe($first->id)
        ->and(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(2);
});

it('refuses to convert again a transfer whose debit leg is not in its accounts currency', function () {
    fxTransferForm($this->user, $this->usd, $this->eur, '100 abroad', rate: '0.90')->call('save');
    [$debit] = fxLegs($this->user, $this->usd);
    Transaction::query()->whereKey($debit->id)->update(['currency' => 'GBP']);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('descriptionInput', '200 abroad')
        ->set('exchangeRate', '0.90')
        ->call('save')
        ->assertHasErrors(['transactionType']);

    expect(Transaction::query()->where('user_id', $this->user->id)->current()->whereNotNull('transfer_pair_id')->pluck('amount')->sort()->values()->all())->toBe([9000, 10000]);
});
