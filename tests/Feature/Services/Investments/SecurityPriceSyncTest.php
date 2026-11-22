<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Console\Commands\SyncSecurityPricesCommand;
use App\Contracts\SecurityPriceProviderContract;
use App\DTOs\SecurityPriceQuote;
use App\DTOs\SecuritySearchResult;
use App\Jobs\SyncSecurityPricesJob;
use App\Models\Account;
use App\Models\Holding;
use App\Models\Security;
use App\Models\SecurityPrice;
use App\Models\User;
use App\Services\Investments\SecurityPriceProviders;
use App\Services\Investments\SecurityPriceSyncer;
use App\Services\Investments\SecurityProviderToggle;
use App\Services\Investments\SecurityRegistrar;
use App\Services\Investments\SecuritySearchService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

final class FakePriceProvider implements SecurityPriceProviderContract
{
    /** @var list<array{string, string}> */
    public array $requested = [];

    /**
     * @param  list<SecuritySearchResult>  $found
     */
    public function __construct(
        private string $name = 'fake',
        private bool $on = true,
        private mixed $quotes = 'default',
        private array $found = [],
    ) {}

    public function key(): string
    {
        return $this->name;
    }

    public function enabled(): bool
    {
        return $this->on;
    }

    public function prices(string $ticker, string $exchangeMic, CarbonInterface $from, CarbonInterface $to): ?array
    {
        $this->requested[] = [$ticker, $exchangeMic];

        if ($this->quotes !== 'default') {
            return $this->quotes;
        }

        $days = (int) $from->diffInDays($to) + 1;

        return collect(range(0, $days - 1))
            ->map(fn (int $offset): SecurityPriceQuote => new SecurityPriceQuote(CarbonImmutable::parse($from->toDateString())->addDays($offset), 10000 + $offset, 'AUD'))
            ->all();
    }

    public function search(string $query): array
    {
        return $this->found;
    }
}

function useProviders(FakePriceProvider ...$providers): void
{
    app()->instance(SecurityPriceProviders::class, new SecurityPriceProviders($providers));
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-31 12:00:00'));
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->account = Account::factory()->investment()->for($this->user)->create();
    $this->security = Security::factory()->create(['ticker' => 'VAS', 'exchange_mic' => 'XASX', 'provider' => 'fake']);
    Holding::factory()->create(['account_id' => $this->account->id, 'security_id' => $this->security->id]);
});

it('dispatches one price job per user with holdings', function () {
    config(['budget.investments.enabled' => true]);
    Queue::fake();
    useProviders(new FakePriceProvider);
    User::factory()->create();
    $second = User::factory()->create();
    Holding::factory()->create(['account_id' => Account::factory()->investment()->for($second)->create()->id, 'security_id' => $this->security->id]);
    Holding::factory()->create(['account_id' => $this->account->id, 'security_id' => Security::factory()->create()->id]);

    $this->artisan(SyncSecurityPricesCommand::class)->assertSuccessful();

    Queue::assertPushed(SyncSecurityPricesJob::class, 2);
    Queue::assertPushed(SyncSecurityPricesJob::class, fn (SyncSecurityPricesJob $job): bool => $job->user->is($this->user));
    Queue::assertPushed(SyncSecurityPricesJob::class, fn (SyncSecurityPricesJob $job): bool => $job->user->is($second));
});

it('dispatches nothing while investments are disabled', function () {
    Queue::fake();

    $this->artisan(SyncSecurityPricesCommand::class)->assertSuccessful();

    Queue::assertNothingPushed();
});

it('is scheduled daily without overlapping and on one server', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains($event->command ?? '', 'securities:sync-prices'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 6 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});

it('upserts prices without duplicates', function () {
    $provider = new FakePriceProvider;
    useProviders($provider);

    $job = new SyncSecurityPricesJob($this->user);
    $job->handle(app(SecurityPriceSyncer::class));
    $job->handle(app(SecurityPriceSyncer::class));

    expect(SecurityPrice::query()->where('security_id', $this->security->id)->count())->toBe(31)
        ->and(SecurityPrice::query()->where('security_id', $this->security->id)->min('date'))->toStartWith('2026-03-01')
        ->and(SecurityPrice::query()->where('security_id', $this->security->id)->max('date'))->toStartWith('2026-03-31');
});

it('updates a changed price for a day instead of adding another row', function () {
    useProviders(new FakePriceProvider);
    app(SecurityPriceSyncer::class)->sync($this->user);

    $this->travelTo(CarbonImmutable::parse('2026-04-01 12:00:00'));
    useProviders(new FakePriceProvider(quotes: [new SecurityPriceQuote(CarbonImmutable::parse('2026-03-31'), 55555, 'AUD')]));
    app(SecurityPriceSyncer::class)->sync($this->user);

    expect(SecurityPrice::query()->where('security_id', $this->security->id)->count())->toBe(31)
        ->and(SecurityPrice::query()->where('security_id', $this->security->id)->whereDate('date', '2026-03-31')->value('price'))->toBe(55555);
});

it('records an error when a provider returns no prices', function () {
    useProviders(new FakePriceProvider);
    app(SecurityPriceSyncer::class)->sync($this->user);
    $before = SecurityPrice::query()->orderBy('id')->pluck('price', 'date')->all();
    Log::spy();

    $this->travelTo(CarbonImmutable::parse('2026-04-01 12:00:00'));
    useProviders(new FakePriceProvider(quotes: []));
    $result = app(SecurityPriceSyncer::class)->sync($this->user);

    expect($result)->toBe(['synced' => 0, 'skipped' => 0, 'failed' => 1])
        ->and($this->security->fresh()->last_error)->toBe('no_prices')
        ->and($this->security->fresh()->last_error_at)->not->toBeNull()
        ->and(SecurityPrice::query()->orderBy('id')->pluck('price', 'date')->all())->toBe($before);

    Log::shouldHaveReceived('warning')->with('Security price sync recorded an error', ['security_id' => $this->security->id, 'error' => 'no_prices']);
});

it('records a provider error separately and keeps the stored prices', function () {
    useProviders(new FakePriceProvider(quotes: null));

    $result = app(SecurityPriceSyncer::class)->sync($this->user);

    expect($result['failed'])->toBe(1)
        ->and($this->security->fresh()->last_error)->toBe('provider_error')
        ->and(SecurityPrice::query()->count())->toBe(0);
});

it('clears the error after a successful sync', function () {
    useProviders(new FakePriceProvider(quotes: null));
    app(SecurityPriceSyncer::class)->sync($this->user);

    useProviders(new FakePriceProvider);
    app(SecurityPriceSyncer::class)->sync($this->user);

    expect($this->security->fresh()->last_error)->toBeNull()
        ->and($this->security->fresh()->last_error_at)->toBeNull();
});

it('skips offline securities and securities whose provider is off', function () {
    $offline = Security::factory()->offline()->create(['provider' => 'fake']);
    $other = Security::factory()->create(['provider' => 'other']);
    Holding::factory()->create(['account_id' => $this->account->id, 'security_id' => $offline->id]);
    Holding::factory()->create(['account_id' => $this->account->id, 'security_id' => $other->id]);
    $provider = new FakePriceProvider;
    useProviders($provider, new FakePriceProvider(name: 'other', on: false));

    $result = app(SecurityPriceSyncer::class)->sync($this->user);

    expect($result)->toBe(['synced' => 1, 'skipped' => 2, 'failed' => 0])
        ->and($provider->requested)->toBe([['VAS', 'XASX']])
        ->and(SecurityPrice::query()->where('security_id', $offline->id)->count())->toBe(0);
});

it('prices only the securities the user holds and gives an unassigned one the enabled provider', function () {
    $unassigned = Security::factory()->create(['provider' => null, 'ticker' => 'NEW']);
    Holding::factory()->create(['account_id' => $this->account->id, 'security_id' => $unassigned->id]);
    $stranger = Account::factory()->investment()->create();
    $theirs = Security::factory()->create(['provider' => 'fake', 'ticker' => 'THEIRS']);
    Holding::factory()->create(['account_id' => $stranger->id, 'security_id' => $theirs->id]);
    $provider = new FakePriceProvider;
    useProviders($provider);

    app(SecurityPriceSyncer::class)->sync($this->user);

    expect(collect($provider->requested)->pluck(0)->sort()->values()->all())->toBe(['NEW', 'VAS'])
        ->and($unassigned->fresh()->provider)->toBe('fake')
        ->and(SecurityPrice::query()->where('security_id', $theirs->id)->count())->toBe(0);
});

it('does nothing for a user without holdings', function () {
    $provider = new FakePriceProvider;
    useProviders($provider);

    $result = app(SecurityPriceSyncer::class)->sync(User::factory()->create());

    expect($result)->toBe(['synced' => 0, 'skipped' => 0, 'failed' => 0])
        ->and($provider->requested)->toBe([]);
});

it('restores only provider-disabled securities on re-enable', function () {
    $health = Security::factory()->offline('delisted')->create(['provider' => 'fake']);
    $disabled = Security::factory()->create(['provider' => 'fake']);
    $other = Security::factory()->create(['provider' => 'other']);
    $toggle = new SecurityProviderToggle(new SecurityPriceProviders([]));

    $toggle->disable('fake');

    expect($disabled->fresh()->offline_reason)->toBe('provider_disabled')
        ->and($disabled->fresh()->offline_at)->not->toBeNull()
        ->and($health->fresh()->offline_reason)->toBe('delisted')
        ->and($other->fresh()->offline_at)->toBeNull()
        ->and($this->security->fresh()->offline_reason)->toBe('provider_disabled');

    $toggle->enable('fake');

    expect($disabled->fresh()->offline_at)->toBeNull()
        ->and($disabled->fresh()->offline_reason)->toBeNull()
        ->and($this->security->fresh()->offline_at)->toBeNull()
        ->and($health->fresh()->offline_reason)->toBe('delisted')
        ->and($health->fresh()->offline_at)->not->toBeNull();
});

it('keeps the original reason of a security that was already offline when its provider is disabled', function () {
    $health = Security::factory()->offline('delisted')->create(['provider' => 'fake']);

    (new SecurityProviderToggle(new SecurityPriceProviders([])))->disable('fake');

    expect($health->fresh()->offline_reason)->toBe('delisted');
});

it('makes each provider\'s securities match whether it is enabled when the command runs', function () {
    config(['budget.investments.enabled' => true]);
    Queue::fake();
    useProviders(new FakePriceProvider(on: false));

    $this->artisan(SyncSecurityPricesCommand::class)->assertSuccessful();

    expect($this->security->fresh()->offline_reason)->toBe('provider_disabled');

    useProviders(new FakePriceProvider(on: true));
    $this->artisan(SyncSecurityPricesCommand::class)->assertSuccessful();

    expect($this->security->fresh()->offline_at)->toBeNull();
});

it('merges search results from all enabled providers by ticker and exchange', function () {
    $first = new FakePriceProvider(name: 'a', found: [
        new SecuritySearchResult('VAS', 'XASX', 'Vanguard Australian Shares', 'AUD', 'a'),
        new SecuritySearchResult('VAS', 'XNYS', 'Other VAS', 'USD', 'a'),
    ]);
    $second = new FakePriceProvider(name: 'b', found: [
        new SecuritySearchResult('VAS', 'XASX', 'Duplicate', 'AUD', 'b'),
        new SecuritySearchResult('VGS', 'XASX', 'Vanguard MSCI', 'AUD', 'b'),
    ]);
    $off = new FakePriceProvider(name: 'c', on: false, found: [new SecuritySearchResult('OFF', 'XASX', 'Off', 'AUD', 'c')]);

    $results = (new SecuritySearchService(new SecurityPriceProviders([$first, $second, $off])))->search(' vas ');

    expect(array_map(fn (SecuritySearchResult $result): string => $result->key().'@'.$result->provider, $results))
        ->toBe(['VAS|XASX@a', 'VAS|XNYS@a', 'VGS|XASX@b']);
});

it('searches nothing for blank text and caps the results', function () {
    $many = collect(range(1, 80))->map(fn (int $number): SecuritySearchResult => new SecuritySearchResult('T'.$number, 'XASX', 'Name', 'AUD', 'a'))->all();
    $service = new SecuritySearchService(new SecurityPriceProviders([new FakePriceProvider(name: 'a', found: $many)]));

    expect($service->search('  '))->toBe([])
        ->and($service->search('t'))->toHaveCount(50);
});

it('finds or creates the global security a search result names, once', function () {
    $registrar = new SecurityRegistrar;
    $result = new SecuritySearchResult(' vgs ', 'xasx', 'Vanguard MSCI', 'AUD', 'twelvedata');

    $created = $registrar->firstOrCreate($result);
    $again = $registrar->firstOrCreate(new SecuritySearchResult('VGS', 'XASX', 'Another name', 'AUD', 'twelvedata'));

    expect($created->ticker)->toBe('VGS')
        ->and($created->exchange_mic)->toBe('XASX')
        ->and($created->provider)->toBe('twelvedata')
        ->and($again->id)->toBe($created->id)
        ->and($again->name)->toBe('Vanguard MSCI')
        ->and(Security::query()->where('ticker', 'VGS')->count())->toBe(1);
});

it('fetches a security once a day however many users hold it', function () {
    $provider = new FakePriceProvider;
    useProviders($provider);
    $second = User::factory()->create();
    Holding::factory()->create(['account_id' => Account::factory()->investment()->for($second)->create()->id, 'security_id' => $this->security->id]);

    $first = app(SecurityPriceSyncer::class)->sync($this->user);
    $other = app(SecurityPriceSyncer::class)->sync($second);
    $forced = app(SecurityPriceSyncer::class)->sync($second, force: true);

    expect($first['synced'])->toBe(1)
        ->and($other)->toBe(['synced' => 0, 'skipped' => 1, 'failed' => 0])
        ->and($forced['synced'])->toBe(1)
        ->and($provider->requested)->toHaveCount(2);
});

it('does not let a rate-limited second run overwrite the error flag of a security refreshed today', function () {
    useProviders(new FakePriceProvider);
    app(SecurityPriceSyncer::class)->sync($this->user);
    $second = User::factory()->create();
    Holding::factory()->create(['account_id' => Account::factory()->investment()->for($second)->create()->id, 'security_id' => $this->security->id]);

    useProviders(new FakePriceProvider(quotes: null));
    app(SecurityPriceSyncer::class)->sync($second);

    expect($this->security->fresh()->last_error)->toBeNull();
});

it('stops calling a provider for the rest of a run once it has failed', function () {
    $other = Security::factory()->create(['provider' => 'fake', 'ticker' => 'VGS']);
    $third = Security::factory()->create(['provider' => 'fake', 'ticker' => 'NDQ']);
    Holding::factory()->create(['account_id' => $this->account->id, 'security_id' => $other->id]);
    Holding::factory()->create(['account_id' => $this->account->id, 'security_id' => $third->id]);
    $provider = new FakePriceProvider(quotes: null);
    useProviders($provider);

    $result = app(SecurityPriceSyncer::class)->sync($this->user);

    expect($provider->requested)->toHaveCount(1)
        ->and($result)->toBe(['synced' => 0, 'skipped' => 2, 'failed' => 1]);
});

it('gives the job a timeout below the queue retry_after', function () {
    expect((new SyncSecurityPricesJob($this->user))->timeout)->toBeLessThan(90);
});

it('syncs only the securities held on the given account', function () {
    $provider = new FakePriceProvider;
    useProviders($provider);
    $second = Account::factory()->investment()->for($this->user)->create();
    $other = Security::factory()->create(['ticker' => 'OTHR', 'exchange_mic' => 'XASX']);
    Holding::factory()->create(['account_id' => $second->id, 'security_id' => $other->id]);
    Holding::factory()->create(['account_id' => $this->account->id, 'security_id' => $this->security->id]);

    app(SecurityPriceSyncer::class)->sync($this->user, accountId: $second->id);

    expect(array_column($provider->requested, 0))->toBe(['OTHR']);
});
