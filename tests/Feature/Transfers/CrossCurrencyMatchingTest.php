<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\ExchangeRateProviderContract;
use App\DTOs\ExchangeRateQuote;
use App\DTOs\NewTransfer;
use App\Enums\TransactionDirection;
use App\Enums\TransferLinkSource;
use App\Exceptions\RealOppositeRowExistsException;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\RedbarkAccount;
use App\Models\RedbarkFeed;
use App\Models\Transaction;
use App\Models\TransferRule;
use App\Models\User;
use App\Services\Transfers\TransferDetector;
use App\Services\Transfers\TransferExchange;
use App\Services\Transfers\TransferLinker;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo('2026-10-15');
    $this->user = User::factory()->create(['base_currency' => 'AUD']);
});

function fxMatchAccount(User $user, string $currency, bool $bankLinked = true): Account
{
    $account = Account::factory()->for($user)->create(['currency' => $currency]);

    if ($bankLinked) {
        RedbarkAccount::factory()->for(RedbarkFeed::query()->where('user_id', $user->id)->first() ?? RedbarkFeed::factory()->for($user)->create(), 'feed')->create(['account_id' => $account->id, 'currency' => $currency]);
    }

    return $account;
}

function fxMatchRow(User $user, Account $account, int $cents, string $date = '2026-10-10'): Transaction
{
    return Transaction::factory()->for($user)->fromRedbark()->create([
        'account_id' => $account->id,
        'direction' => $cents < 0 ? TransactionDirection::Debit : TransactionDirection::Credit,
        'amount' => $cents,
        'post_date' => $date,
        'description' => 'Transfer',
    ]);
}

function fxMatchRate(string $from, string $to, float $rate, string $date = '2026-10-10'): void
{
    ExchangeRate::factory()->create(['from_currency' => $from, 'to_currency' => $to, 'rate' => $rate, 'rate_date' => $date]);
}

function fxMatchViaBase(string $from, string $to, string $date = '2026-10-10'): void
{
    fxMatchRate($from, 'AUD', 1.5, $date);
    fxMatchRate('AUD', $to, 0.6, $date);
}

it('suggests a cross-currency pair within the tolerance', function () {
    fxMatchViaBase('USD', 'EUR');
    $usd = fxMatchAccount($this->user, 'USD');
    $eur = fxMatchAccount($this->user, 'EUR');
    $out = fxMatchRow($this->user, $usd, -10000);
    $in = fxMatchRow($this->user, $eur, 9300, '2026-10-11');

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($out->fresh()->suggested_pair_id)->toBe($in->id)
        ->and($in->fresh()->suggested_pair_id)->toBe($out->id)
        ->and($out->fresh()->transfer_pair_id)->toBeNull();
});

it('does not suggest a cross-currency pair beyond the tolerance', function () {
    fxMatchViaBase('USD', 'EUR');
    $usd = fxMatchAccount($this->user, 'USD');
    $eur = fxMatchAccount($this->user, 'EUR');
    $out = fxMatchRow($this->user, $usd, -10000);
    fxMatchRow($this->user, $eur, 11100);

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($out->fresh()->suggested_pair_id)->toBeNull();
});

it('caps the cross-currency tolerance at fifty percent', function () {
    config(['budget.transfer_fx_tolerance' => 80]);
    fxMatchViaBase('USD', 'EUR');
    fxMatchViaBase('USD', 'EUR', '2026-09-20');
    $usdA = fxMatchAccount($this->user, 'USD');
    $eurA = fxMatchAccount($this->user, 'EUR');
    $usdB = fxMatchAccount($this->user, 'USD');
    $eurB = fxMatchAccount($this->user, 'EUR');

    $beyondOut = fxMatchRow($this->user, $usdA, -10000);
    $beyondIn = fxMatchRow($this->user, $eurA, 14000);
    $withinOut = fxMatchRow($this->user, $usdB, -10000, '2026-09-20');
    $withinIn = fxMatchRow($this->user, $eurB, 12000, '2026-09-20');

    $this->artisan('transfers:detect')->assertSuccessful();

    expect(app(TransferExchange::class)->tolerancePercent())->toBe(50.0)
        ->and($beyondOut->fresh()->suggested_pair_id)->toBeNull()
        ->and($beyondIn->fresh()->suggested_pair_id)->toBeNull()
        ->and($withinOut->fresh()->suggested_pair_id)->toBe($withinIn->id);
});

it('defaults the tolerance to ten percent and clamps a negative one to zero', function () {
    expect(app(TransferExchange::class)->tolerancePercent())->toBe(10.0);

    config(['budget.transfer_fx_tolerance' => -5]);

    expect(app(TransferExchange::class)->tolerancePercent())->toBe(0.0);
});

it('accepts a converted amount exactly at the tolerance and refuses one cent beyond', function (int $credit, bool $expected) {
    fxMatchViaBase('USD', 'EUR');
    $out = fxMatchRow($this->user, fxMatchAccount($this->user, 'USD'), -10000);
    $in = fxMatchRow($this->user, fxMatchAccount($this->user, 'EUR'), $credit);

    expect(app(TransferExchange::class)->qualifies($out->fresh(), $in->fresh()))->toBe($expected);
})->with([
    'upper edge' => [9900, true],
    'just above' => [9901, false],
    'lower edge' => [8100, true],
    'just below' => [8099, false],
]);

it('compares against the converted amount in whole cents at zero tolerance', function (int $credit, bool $expected) {
    config(['budget.transfer_fx_tolerance' => 0]);
    fxMatchViaBase('USD', 'EUR');
    $out = fxMatchRow($this->user, fxMatchAccount($this->user, 'USD'), -10001);
    $in = fxMatchRow($this->user, fxMatchAccount($this->user, 'EUR'), $credit);

    expect(app(TransferExchange::class)->qualifies($out->fresh(), $in->fresh()))->toBe($expected);
})->with([
    'the amount a write would produce' => [9001, true],
    'one cent off' => [9002, false],
]);

it('looks a transfer dated in the future up at todays rate', function () {
    fxMatchViaBase('USD', 'EUR', '2026-10-15');

    expect(app(TransferExchange::class)->incoming($this->user->id, 10000, 'USD', 'EUR', CarbonImmutable::parse('2026-12-01')))->toBe(9000);
});

it('does not suggest a cross-currency pair when a rate is missing', function () {
    $gbp = fxMatchAccount($this->user, 'GBP');
    $eur = fxMatchAccount($this->user, 'EUR');
    $out = fxMatchRow($this->user, $gbp, -10000);
    $in = fxMatchRow($this->user, $eur, 11500);

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($out->fresh()->suggested_pair_id)->toBeNull();

    fxMatchRate('GBP', 'AUD', 1.9);
    fxMatchRate('AUD', 'EUR', 0.6);

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($out->fresh()->suggested_pair_id)->toBe($in->id);
});

it('prefers an exact same-currency match over a converted guess', function () {
    fxMatchViaBase('USD', 'EUR');
    $usd = fxMatchAccount($this->user, 'USD');
    $otherUsd = fxMatchAccount($this->user, 'USD');
    $eur = fxMatchAccount($this->user, 'EUR');
    $out = fxMatchRow($this->user, $usd, -10000);
    $exact = fxMatchRow($this->user, $otherUsd, 10000);
    $guess = fxMatchRow($this->user, $eur, 9000);

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($out->fresh()->suggested_pair_id)->toBe($exact->id)
        ->and($guess->fresh()->suggested_pair_id)->toBeNull();
});

it('does not suggest a cross-currency pair between accounts that are not bank-linked', function () {
    fxMatchViaBase('USD', 'EUR');
    $usd = fxMatchAccount($this->user, 'USD', bankLinked: false);
    $eur = fxMatchAccount($this->user, 'EUR');
    $out = fxMatchRow($this->user, $usd, -10000);
    fxMatchRow($this->user, $eur, 9000);

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($out->fresh()->suggested_pair_id)->toBeNull();
});

it('suggests a cross-currency pair between unlinked accounts when a transfer rule confirms the direction', function () {
    fxMatchViaBase('USD', 'EUR');
    $usd = fxMatchAccount($this->user, 'USD', bankLinked: false);
    $eur = fxMatchAccount($this->user, 'EUR', bankLinked: false);
    TransferRule::query()->create(['user_id' => $this->user->id, 'account_id' => $usd->id, 'counterpart_account_id' => $eur->id, 'description_pattern' => 'Unrelated pattern']);
    $out = fxMatchRow($this->user, $usd, -10000);
    $in = fxMatchRow($this->user, $eur, 9000);

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($out->fresh()->suggested_pair_id)->toBe($in->id);
});

it('does not use a rule for the opposite direction', function () {
    fxMatchViaBase('USD', 'EUR');
    $usd = fxMatchAccount($this->user, 'USD', bankLinked: false);
    $eur = fxMatchAccount($this->user, 'EUR', bankLinked: false);
    TransferRule::query()->create(['user_id' => $this->user->id, 'account_id' => $eur->id, 'counterpart_account_id' => $usd->id, 'description_pattern' => 'Unrelated pattern']);
    $out = fxMatchRow($this->user, $usd, -10000);
    fxMatchRow($this->user, $eur, 9000);

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($out->fresh()->suggested_pair_id)->toBeNull();
});

it('suggests but never auto-links a cross-currency pair along a remembered rule', function () {
    fxMatchViaBase('USD', 'EUR');
    $usd = fxMatchAccount($this->user, 'USD', bankLinked: false);
    $eur = fxMatchAccount($this->user, 'EUR', bankLinked: false);
    TransferRule::query()->create(['user_id' => $this->user->id, 'account_id' => $usd->id, 'counterpart_account_id' => $eur->id, 'description_pattern' => 'Transfer']);
    $out = fxMatchRow($this->user, $usd, -10000);
    $in = fxMatchRow($this->user, $eur, 9100);

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($out->fresh()->transfer_pair_id)->toBeNull()
        ->and($in->fresh()->transfer_pair_id)->toBeNull()
        ->and($out->fresh()->suggested_pair_id)->toBe($in->id)
        ->and($out->fresh()->transfer_link_source)->toBe(TransferLinkSource::Suggested);
});

it('leaves a cross-currency row ambiguous when two credits qualify', function () {
    fxMatchViaBase('USD', 'EUR');
    $usd = fxMatchAccount($this->user, 'USD');
    $eur = fxMatchAccount($this->user, 'EUR');
    $out = fxMatchRow($this->user, $usd, -10000);
    fxMatchRow($this->user, $eur, 9000);
    fxMatchRow($this->user, $eur, 9100);

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($out->fresh()->suggested_pair_id)->toBeNull();
});

it('neither suggests nor offers an equal-number pair in different currencies without a rate', function () {
    $usd = fxMatchAccount($this->user, 'USD');
    $eur = fxMatchAccount($this->user, 'EUR');
    $out = fxMatchRow($this->user, $usd, -10000);
    fxMatchRow($this->user, $eur, 10000);

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($out->fresh()->suggested_pair_id)->toBeNull()
        ->and(app(TransferLinker::class)->candidatesFor($out->fresh(), crossCurrency: true))->toHaveCount(0);
});

it('does not let an equal-number foreign credit make a same-currency pair ambiguous', function () {
    $usd = fxMatchAccount($this->user, 'USD');
    $otherUsd = fxMatchAccount($this->user, 'USD');
    $eur = fxMatchAccount($this->user, 'EUR');
    $out = fxMatchRow($this->user, $usd, -10000);
    $in = fxMatchRow($this->user, $otherUsd, 10000);
    fxMatchRow($this->user, $eur, 10000);

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($out->fresh()->suggested_pair_id)->toBe($in->id);
    expect(app(TransferLinker::class)->candidatesFor($out->fresh())->pluck('id')->all())->toBe([$in->id]);
});

it('offers a converted candidate even when an equal-number foreign row sits beside it', function () {
    fxMatchViaBase('USD', 'EUR');
    $usd = fxMatchAccount($this->user, 'USD');
    $eurA = fxMatchAccount($this->user, 'EUR');
    $eurB = fxMatchAccount($this->user, 'EUR');
    $out = fxMatchRow($this->user, $usd, -10000);
    fxMatchRow($this->user, $eurA, 10000);
    $near = fxMatchRow($this->user, $eurB, 9000);

    $candidates = app(TransferLinker::class)->candidatesFor($out->fresh(), crossCurrency: true);

    expect($candidates->pluck('id')->all())->toBe([$near->id]);
});

it('does not run the cross-currency pass for single-currency history', function () {
    $a = fxMatchAccount($this->user, 'AUD');
    $b = fxMatchAccount($this->user, 'AUD');
    fxMatchRow($this->user, $a, -10000);
    fxMatchRow($this->user, $b, 7777);

    DB::enableQueryLog();
    $this->artisan('transfers:detect')->assertSuccessful();
    $tables = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'redbark_accounts'));

    expect($tables)->toHaveCount(0);
});

it('pairs only rows within the date window in the cross-currency pass', function () {
    fxMatchViaBase('USD', 'EUR');
    $usd = fxMatchAccount($this->user, 'USD');
    $eur = fxMatchAccount($this->user, 'EUR');
    $out = fxMatchRow($this->user, $usd, -10000, '2026-10-10');
    $far = fxMatchRow($this->user, $eur, 9000, '2026-10-14');
    $edge = fxMatchRow($this->user, $eur, 9100, '2026-10-13');

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($out->fresh()->suggested_pair_id)->toBe($edge->id)
        ->and($far->fresh()->suggested_pair_id)->toBeNull();
});

it('ignores a direct quote between two non-base currencies when the base rate is missing', function () {
    app()->bind(ExchangeRateProviderContract::class, fn () => new class implements ExchangeRateProviderContract
    {
        public function rate(string $from, string $to, CarbonInterface $date): ?ExchangeRateQuote
        {
            return $from === 'GBP' && $to === 'EUR' ? new ExchangeRateQuote(1.15, CarbonImmutable::parse($date->toDateString())) : null;
        }
    });
    fxMatchRate('GBP', 'EUR', 1.15);
    $gbp = fxMatchAccount($this->user, 'GBP');
    $eur = fxMatchAccount($this->user, 'EUR');
    $out = fxMatchRow($this->user, $gbp, -10000);
    fxMatchRow($this->user, $eur, 11500);

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($out->fresh()->suggested_pair_id)->toBeNull();

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-10-10')
        ->set('transactionType', 'transfer')
        ->set('accountId', $gbp->id)
        ->set('transferToAccountId', $eur->id)
        ->assertSee('unavailable');
});

it('lists the exact same-currency row before a converted one and finds the debit from the credit side', function () {
    fxMatchViaBase('USD', 'EUR');
    $usd = fxMatchAccount($this->user, 'USD');
    $otherUsd = fxMatchAccount($this->user, 'USD');
    $eur = fxMatchAccount($this->user, 'EUR');
    $out = fxMatchRow($this->user, $usd, -10000);
    $near = fxMatchRow($this->user, $eur, 9000);
    $exact = fxMatchRow($this->user, $otherUsd, 10000);

    expect(app(TransferLinker::class)->candidatesFor($out->fresh(), crossCurrency: true)->pluck('id')->all())->toBe([$exact->id, $near->id])
        ->and(app(TransferLinker::class)->candidatesFor($near->fresh(), crossCurrency: true)->pluck('id')->all())->toBe([$out->id]);
});

it('reads the exchange rates once for every converted candidate in one search', function () {
    fxMatchViaBase('USD', 'EUR');
    $usd = fxMatchAccount($this->user, 'USD');
    $eur = fxMatchAccount($this->user, 'EUR');
    $out = fxMatchRow($this->user, $usd, -10000);

    foreach ([8800, 8900, 9000, 9100, 9200] as $cents) {
        fxMatchRow($this->user, $eur, $cents);
    }

    DB::enableQueryLog();
    $found = app(TransferLinker::class)->candidatesFor($out->fresh(), crossCurrency: true);
    $rateReads = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'exchange_rates'))->count();

    expect($found)->toHaveCount(5)
        ->and($rateReads)->toBe(2);
});

it('refuses a hidden-account mirror beside a real converted opposite row', function () {
    fxMatchViaBase('USD', 'EUR');
    $hidden = Account::factory()->for($this->user)->untracked()->create(['currency' => 'EUR']);
    $feed = fxMatchRow($this->user, fxMatchAccount($this->user, 'USD'), -10000);
    fxMatchRow($this->user, fxMatchAccount($this->user, 'EUR'), 9000);

    expect(fn () => app(TransferLinker::class)->linkToUntrackedAccount($feed->fresh(), $hidden, TransferLinkSource::Manual))
        ->toThrow(RealOppositeRowExistsException::class);

    expect(Transaction::query()->where('account_id', $hidden->id)->count())->toBe(0);
});

it('refuses to create a missing leg beside a real converted row that arrived on that account', function () {
    fxMatchViaBase('USD', 'EUR');
    $feed = fxMatchRow($this->user, fxMatchAccount($this->user, 'USD'), -10000);
    $eur = fxMatchAccount($this->user, 'EUR');
    fxMatchRow($this->user, $eur, 9000);

    expect(fn () => app(TransferLinker::class)->createMissingLeg($feed->fresh(), $eur, TransferLinkSource::Manual))
        ->toThrow(RealOppositeRowExistsException::class);
});

it('reports the same rule-pass outcome in a dry run as a real run when the hidden counterpart has no rate', function () {
    $usd = fxMatchAccount($this->user, 'USD');
    $hidden = Account::factory()->for($this->user)->untracked()->create(['currency' => 'EUR']);
    TransferRule::query()->create(['user_id' => $this->user->id, 'account_id' => $usd->id, 'counterpart_account_id' => $hidden->id, 'description_pattern' => 'Transfer']);
    $feed = fxMatchRow($this->user, $usd, -10000);

    $dry = app(TransferDetector::class)->run($this->user, dryRun: true);
    $real = app(TransferDetector::class)->run($this->user);

    expect($dry['rule'])->toBe(0)
        ->and($real['rule'])->toBe(0)
        ->and($feed->fresh()->transfer_pair_id)->toBeNull();

    fxMatchViaBase('USD', 'EUR');

    expect(app(TransferDetector::class)->run($this->user, dryRun: true)['rule'])->toBe(1)
        ->and(app(TransferDetector::class)->run($this->user)['rule'])->toBe(1)
        ->and($feed->fresh()->transfer_pair_id)->not->toBeNull();
});

it('looks the rate up before the write transaction opens', function () {
    $levels = new ArrayObject;
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
    $usd = fxMatchAccount($this->user, 'USD');
    $eur = fxMatchAccount($this->user, 'EUR');
    $baseline = DB::transactionLevel();

    $debit = app(TransferLinker::class)->create(new NewTransfer(
        userId: $this->user->id,
        fromAccountId: $usd->id,
        toAccountId: $eur->id,
        amount: 10000,
        description: 'abroad',
        postDate: '2026-10-10',
    ));

    expect($levels->getArrayCopy())->not->toBeEmpty()
        ->each->toBe($baseline)
        ->and($debit->transferPair->amount)->toBe(9000);
});
