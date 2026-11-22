<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\SecurityPriceProviderContract;
use App\DTOs\RecordTrade;
use App\DTOs\SecurityPriceQuote;
use App\Enums\TradeActivity;
use App\Models\Account;
use App\Models\AccountDailyBalance;
use App\Models\ExchangeRate;
use App\Models\Holding;
use App\Models\Security;
use App\Models\SecurityPrice;
use App\Models\User;
use App\Services\AccountValuationService;
use App\Services\Investments\HoldingDeleter;
use App\Services\Investments\HoldingRemapper;
use App\Services\Investments\SecurityPriceProviders;
use App\Services\Investments\SecurityPriceSyncer;
use App\Services\Investments\TradeRecorder;
use App\Services\NetWorthService;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class ValuationFakeProvider implements SecurityPriceProviderContract
{
    /** @param list<SecurityPriceQuote> $quotes */
    public function __construct(private array $quotes) {}

    public function key(): string
    {
        return 'valuation-fake';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function prices(string $ticker, string $exchangeMic, CarbonInterface $from, CarbonInterface $to): ?array
    {
        return $this->quotes;
    }

    public function search(string $query): array
    {
        return [];
    }
}

beforeEach(function (): void {
    $this->travelTo('2026-03-05 12:00:00');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->investment()->for($this->user)->create(['balance' => 103000]);
    $this->security = Security::factory()->create(['currency' => 'AUD', 'ticker' => 'VAS']);
    app(AccountValuationService::class)->setOpeningBalance($this->account, 100000, CarbonImmutable::parse('2026-03-01'));
    app(AccountValuationService::class)->reconcile($this->account->fresh(), 103000, CarbonImmutable::parse('2026-03-05'));
    $this->from = CarbonImmutable::parse('2026-03-01');
    $this->to = CarbonImmutable::parse('2026-03-05');
});

function valuationPrices(mixed $test, array $prices, ?Security $security = null, string $currency = 'AUD'): void
{
    foreach ($prices as $date => $price) {
        SecurityPrice::factory()->create(['security_id' => ($security ?? $test->security)->id, 'currency' => $currency, 'date' => $date, 'price' => $price]);
    }
}

function valuationTrade(mixed $test, TradeActivity $activity, string $quantity, int $price, string $date, ?Security $security = null, string $currency = 'AUD'): void
{
    app(TradeRecorder::class)->record($test->user, new RecordTrade(accountId: $test->account->id, activity: $activity, date: $date, securityId: ($security ?? $test->security)->id, quantity: $quantity, price: $price, currency: $currency));
}

function valuationRows(Account $account): array
{
    return $account->dailyBalances()->orderBy('balance_date')->get()->keyBy(fn (AccountDailyBalance $row): string => $row->balance_date->toDateString())->all();
}

function valuationRebuild(mixed $test): void
{
    app(AccountBalanceHistoryBuilder::class)->rebuild($test->account->fresh());
}

it('values an investment account at cash plus holdings market value each day', function () {
    valuationPrices($this, ['2026-03-01' => 1500, '2026-03-02' => 1500, '2026-03-03' => 1800, '2026-03-04' => 1800, '2026-03-05' => 1800]);
    valuationTrade($this, TradeActivity::Buy, '10', 1500, '2026-03-01');

    $rows = valuationRows($this->account->fresh());
    $series = app(NetWorthService::class)->accountSeries($this->user, [$this->account->id], $this->from, $this->to)[$this->account->id];

    expect(array_values($series))->toBe([100000, 100000, 103000, 103000, 103000])
        ->and(array_map(fn ($row) => $row->end_balance, array_values($rows)))->toBe([85000, 85000, 85000, 85000, 85000])
        ->and(array_map(fn ($row) => $row->end_non_cash_balance, array_values($rows)))->toBe([15000, 15000, 18000, 18000, 18000])
        ->and(array_map(fn ($row) => $row->start_non_cash_balance, array_values($rows)))->toBe([0, 15000, 15000, 18000, 18000])
        ->and(array_map(fn ($row) => $row->market_movement, array_values($rows)))->toBe([0, 0, 3000, 0, 0]);
});

it('ends today at the reconciled balance', function () {
    valuationPrices($this, ['2026-03-01' => 1500, '2026-03-03' => 1800]);
    valuationTrade($this, TradeActivity::Buy, '10', 1500, '2026-03-01');

    $today = valuationRows($this->account->fresh())['2026-03-05'];

    expect($today->end_balance + $today->end_non_cash_balance)->toBe($this->account->fresh()->balance)
        ->and($today->end_balance)->toBe(103000 - 18000)
        ->and($today->adjustments)->toBe(0);
});

it('values a position at its last trade price before any price is stored', function () {
    valuationTrade($this, TradeActivity::Buy, '10', 1500, '2026-03-01');

    $rows = array_values(valuationRows($this->account->fresh()));

    expect(array_map(fn ($row) => $row->end_non_cash_balance, $rows))->toBe([15000, 15000, 15000, 15000, 15000])
        ->and(array_map(fn ($row) => $row->market_movement, $rows))->toBe([0, 0, 0, 0, 0]);
});

it('carries the latest price over days without a price', function () {
    valuationPrices($this, ['2026-03-02' => 1600, '2026-03-04' => 1800]);
    valuationTrade($this, TradeActivity::Buy, '10', 1500, '2026-03-01');

    $rows = array_values(valuationRows($this->account->fresh()));

    expect(array_map(fn ($row) => $row->end_non_cash_balance, $rows))->toBe([15000, 16000, 16000, 18000, 18000]);
});

it('values a sold-out position at zero', function () {
    valuationPrices($this, ['2026-03-01' => 1500, '2026-03-03' => 1800]);
    valuationTrade($this, TradeActivity::Buy, '10', 1500, '2026-03-01');
    valuationTrade($this, TradeActivity::Sell, '10', 1800, '2026-03-03');

    $rows = valuationRows($this->account->fresh());

    expect(array_map(fn ($row) => $row->end_non_cash_balance, array_values($rows)))->toBe([15000, 15000, 0, 0, 0])
        ->and($rows['2026-03-03']->market_movement)->toBe(3000)
        ->and($rows['2026-03-04']->end_balance)->toBe(103000)
        ->and(Holding::query()->count())->toBe(0);
});

it('converts a foreign security at each day\'s rate', function () {
    config(['budget.multi_currency' => true]);
    $us = Security::factory()->create(['currency' => 'USD', 'ticker' => 'VTI']);
    foreach (['2026-03-01' => 1.5, '2026-03-02' => 1.5, '2026-03-03' => 1.6, '2026-03-04' => 1.6, '2026-03-05' => 1.6] as $date => $rate) {
        ExchangeRate::factory()->create(['from_currency' => 'USD', 'to_currency' => 'AUD', 'rate_date' => $date, 'rate' => $rate]);
    }
    valuationPrices($this, ['2026-03-01' => 1000, '2026-03-03' => 1000], $us, 'USD');
    valuationTrade($this, TradeActivity::Buy, '10', 1000, '2026-03-01', $us, 'USD');

    $rows = array_values(valuationRows($this->account->fresh()));

    expect(array_map(fn ($row) => $row->end_non_cash_balance, $rows))->toBe([15000, 15000, 16000, 16000, 16000])
        ->and(array_map(fn ($row) => $row->market_movement, $rows))->toBe([0, 0, 1000, 0, 0]);
});

it('does not split a provider-linked account', function () {
    $linked = Account::factory()->investment()->withRedbark()->for($this->user)->create(['balance' => 50000]);
    app(AccountValuationService::class)->reconcile($linked, 50000, CarbonImmutable::parse('2026-03-05'));
    valuationPrices($this, ['2026-03-01' => 1500]);
    app(TradeRecorder::class)->record($this->user, new RecordTrade(accountId: $linked->id, activity: TradeActivity::Buy, date: '2026-03-01', securityId: $this->security->id, quantity: '10', price: 1500));
    app(AccountBalanceHistoryBuilder::class)->rebuild($linked->fresh());

    $rows = $linked->dailyBalances()->get();

    expect($rows)->not->toBeEmpty()
        ->and($rows->pluck('end_non_cash_balance')->filter(fn ($value) => $value !== null))->toBeEmpty()
        ->and($rows->pluck('market_movement')->filter(fn ($value) => $value !== null))->toBeEmpty()
        ->and($rows->last()->end_balance)->toBe(50000)
        ->and(app(NetWorthService::class)->accountSeries($this->user, [$linked->id], $this->to, $this->to)[$linked->id]['2026-03-05'])->toBe(50000);
});

it('keeps an excluded investment account out of the net worth series', function () {
    valuationPrices($this, ['2026-03-01' => 1500, '2026-03-03' => 1800]);
    valuationTrade($this, TradeActivity::Buy, '10', 1500, '2026-03-01');

    expect(app(NetWorthService::class)->series($this->user, $this->from, $this->to)['2026-03-03'])->toBe(103000);

    $this->account->forceFill(['exclude_from_reports' => true])->save();

    expect(array_sum(app(NetWorthService::class)->series($this->user->fresh(), $this->from, $this->to)))->toBe(0);
});

it('reads total as cash plus non-cash in the net worth series', function () {
    valuationPrices($this, ['2026-03-01' => 1500, '2026-03-03' => 1800]);
    valuationTrade($this, TradeActivity::Buy, '10', 1500, '2026-03-01');

    $series = app(NetWorthService::class)->series($this->user, $this->from, $this->to);
    $rows = valuationRows($this->account->fresh());

    foreach ($rows as $day => $row) {
        expect($series[$day])->toBe($row->end_balance + $row->end_non_cash_balance);
    }

    expect($series['2026-03-03'])->toBeGreaterThan($rows['2026-03-03']->end_balance);
});

it('rebuilds the history after trades, remaps and price syncs', function () {
    valuationTrade($this, TradeActivity::Buy, '10', 1500, '2026-03-01');

    expect(valuationRows($this->account->fresh())['2026-03-03']->end_non_cash_balance)->toBe(15000);

    $quotes = array_map(fn (string $date, int $price): SecurityPriceQuote => new SecurityPriceQuote(CarbonImmutable::parse($date), $price, 'AUD'), ['2026-03-03', '2026-03-04'], [1800, 1800]);
    app()->instance(SecurityPriceProviders::class, new SecurityPriceProviders([new ValuationFakeProvider($quotes)]));
    app(SecurityPriceSyncer::class)->sync($this->user, force: true);

    expect(valuationRows($this->account->fresh())['2026-03-03']->end_non_cash_balance)->toBe(18000);

    $target = Security::factory()->create(['currency' => 'AUD', 'ticker' => 'VGS']);
    valuationPrices($this, ['2026-03-01' => 2000], $target);
    app(HoldingRemapper::class)->remap($this->user, Holding::query()->sole(), $target);

    expect(valuationRows($this->account->fresh())['2026-03-03']->end_non_cash_balance)->toBe(20000);

    app(HoldingDeleter::class)->delete($this->user, Holding::query()->sole());

    expect(valuationRows($this->account->fresh())['2026-03-03']->end_non_cash_balance)->toBeNull();
});

it('rebuilds the history of the user\'s accounts when another user\'s sync already priced the security', function () {
    valuationTrade($this, TradeActivity::Buy, '10', 1500, '2026-03-01');
    $quotes = [new SecurityPriceQuote(CarbonImmutable::parse('2026-03-03'), 1800, 'AUD')];
    app()->instance(SecurityPriceProviders::class, new SecurityPriceProviders([new ValuationFakeProvider($quotes)]));
    $other = User::factory()->create();
    $theirs = Account::factory()->investment()->for($other)->create(['balance' => 50000]);
    Holding::factory()->create(['account_id' => $theirs->id, 'security_id' => $this->security->id]);
    app(SecurityPriceSyncer::class)->sync($other);

    app(SecurityPriceSyncer::class)->sync($this->user);

    expect(valuationRows($this->account->fresh())['2026-03-03']->end_non_cash_balance)->toBe(18000);
});

it('leaves an account without share trades cash-only', function () {
    $plain = Account::factory()->for($this->user)->create(['balance' => 1000]);
    app(AccountBalanceHistoryBuilder::class)->rebuild($plain);

    expect($plain->dailyBalances()->whereNotNull('end_non_cash_balance')->count())->toBe(0);

    valuationRebuild($this);

    expect($this->account->dailyBalances()->whereNotNull('end_non_cash_balance')->count())->toBe(0);
});

it('works backwards from the balance for an account with no valuation', function () {
    $fresh = Account::factory()->investment()->for($this->user)->create(['balance' => 103000]);
    valuationPrices($this, ['2026-03-01' => 1500, '2026-03-03' => 1800]);
    app(TradeRecorder::class)->record($this->user, new RecordTrade(accountId: $fresh->id, activity: TradeActivity::Buy, date: '2026-03-01', securityId: $this->security->id, quantity: '10', price: 1500));

    $series = app(NetWorthService::class)->accountSeries($this->user, [$fresh->id], $this->from, $this->to)[$fresh->id];
    $rows = valuationRows($fresh->fresh());

    expect(array_values($series))->toBe([100000, 100000, 103000, 103000, 103000])
        ->and($rows['2026-03-05']->end_balance + $rows['2026-03-05']->end_non_cash_balance)->toBe($fresh->fresh()->balance);
});

it('moves the stored balance with the market after the last valuation', function () {
    $fresh = Account::factory()->investment()->for($this->user)->create(['balance' => 0]);
    app(AccountValuationService::class)->setOpeningBalance($fresh, 100000, CarbonImmutable::parse('2026-03-01'));
    valuationPrices($this, ['2026-03-01' => 1500, '2026-03-03' => 1800]);
    app(TradeRecorder::class)->record($this->user, new RecordTrade(accountId: $fresh->id, activity: TradeActivity::Buy, date: '2026-03-01', securityId: $this->security->id, quantity: '10', price: 1500));

    $today = valuationRows($fresh->fresh())['2026-03-05'];

    expect($fresh->fresh()->balance)->toBe(103000)
        ->and($today->end_balance + $today->end_non_cash_balance)->toBe(103000);

    $valuation = app(AccountValuationService::class)->reconcile($fresh->fresh(), 103000, CarbonImmutable::parse('2026-03-05'));

    expect($valuation->difference)->toBe(0)
        ->and(valuationRows($fresh->fresh())['2026-03-05']->adjustments)->toBe(0)
        ->and($fresh->fresh()->balance)->toBe(103000);
});

it('values a position at the last trade price, not the first', function () {
    valuationTrade($this, TradeActivity::Buy, '10', 1500, '2026-03-01');
    valuationTrade($this, TradeActivity::Buy, '10', 2000, '2026-03-03');

    $rows = array_values(valuationRows($this->account->fresh()));

    expect(array_map(fn ($row) => $row->end_non_cash_balance, $rows))->toBe([15000, 15000, 40000, 40000, 40000]);
});

it('does not value dividends, interest or fees as shares', function () {
    valuationTrade($this, TradeActivity::Buy, '10', 1500, '2026-03-01');
    app(TradeRecorder::class)->record($this->user, new RecordTrade(accountId: $this->account->id, activity: TradeActivity::Dividend, date: '2026-03-03', securityId: $this->security->id, amount: 500));

    $rows = array_values(valuationRows($this->account->fresh()));

    expect(array_map(fn ($row) => $row->end_non_cash_balance, $rows))->toBe([15000, 15000, 15000, 15000, 15000])
        ->and(array_map(fn ($row) => $row->market_movement, $rows))->toBe([0, 0, 0, 0, 0]);
});

it('rebuilds the history when a trade is edited', function () {
    valuationPrices($this, ['2026-03-01' => 1500, '2026-03-03' => 1800]);
    valuationTrade($this, TradeActivity::Buy, '10', 1500, '2026-03-01');

    app(TradeRecorder::class)->update($this->user, App\Models\Trade::query()->sole(), quantity: '5');

    expect(valuationRows($this->account->fresh())['2026-03-03']->end_non_cash_balance)->toBe(9000);
});

it('stores the same rows from a partial rebuild as from a full one', function () {
    valuationPrices($this, ['2026-03-01' => 1500, '2026-03-03' => 1800]);
    valuationTrade($this, TradeActivity::Buy, '10', 1500, '2026-03-03');

    $partial = array_map(fn ($row) => $row->only(['balance_date', 'start_balance', 'end_balance', 'start_non_cash_balance', 'end_non_cash_balance', 'market_movement', 'adjustments']), array_values(valuationRows($this->account->fresh())));

    valuationRebuild($this);

    $full = array_map(fn ($row) => $row->only(['balance_date', 'start_balance', 'end_balance', 'start_non_cash_balance', 'end_non_cash_balance', 'market_movement', 'adjustments']), array_values(valuationRows($this->account->fresh())));

    expect($partial)->toEqual($full);
});

it('carries the total of an earlier row into a later range', function () {
    valuationPrices($this, ['2026-03-01' => 1500, '2026-03-03' => 1800]);
    valuationTrade($this, TradeActivity::Buy, '10', 1500, '2026-03-01');
    $this->travelTo('2026-03-08 12:00:00');

    $series = app(NetWorthService::class)->series($this->user, CarbonImmutable::parse('2026-03-07'), CarbonImmutable::parse('2026-03-08'));

    expect($series)->toBe(['2026-03-07' => 103000, '2026-03-08' => 103000]);
});
