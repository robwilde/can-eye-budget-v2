<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\ContextDevServiceContract;
use App\DTOs\MerchantBrandData;
use App\Enums\MerchantBrandStatus;
use App\Enums\TransactionDirection;
use App\Jobs\ResolveMerchantBrandJob;
use App\Models\MerchantBrand;
use App\Models\Transaction;
use App\Models\User;
use ContextDev\Core\Exceptions\APIConnectionException;
use GuzzleHttp\Psr7\Request;

beforeEach(function () {
    config([
        'services.context_dev.enrichment_enabled' => true,
        'services.context_dev.daily_credit_cap' => 500,
    ]);

    $this->user = User::factory()->create();
    $this->contextDev = Mockery::mock(ContextDevServiceContract::class);
    app()->instance(ContextDevServiceContract::class, $this->contextDev);
});

function woolworthsRow(User $user, array $attributes = []): Transaction
{
    return Transaction::factory()->for($user)->create([
        'description' => 'VISA WOOLWORTHS 1234 SYDNEY',
        'direction' => TransactionDirection::Debit,
        'merchant_name' => null,
        ...$attributes,
    ]);
}

function runResolve(User $user, string $key = 'VISA WOOLWORTHS SYDNEY'): void
{
    dispatch_sync(new ResolveMerchantBrandJob($user, $key));
}

it('stores a resolved brand without touching the transaction', function () {
    $row = woolworthsRow($this->user, ['enrich_data' => ['redbark' => ['merchantCategoryCode' => '5411']]]);
    $keyBefore = $row->merchant_key;

    $this->contextDev->shouldReceive('brandFromTransaction')
        ->once()
        ->with('VISA WOOLWORTHS SYDNEY', null, null, '5411')
        ->andReturn(new MerchantBrandData(title: 'Woolworths', domain: 'woolworths.com.au', logoUrl: 'https://cdn/w.png'));

    runResolve($this->user, $keyBefore);

    $brand = MerchantBrand::query()->sole();

    expect($brand->status)->toBe(MerchantBrandStatus::Resolved)
        ->and($brand->merchant_key)->toBe($keyBefore)
        ->and($brand->title)->toBe('Woolworths')
        ->and($brand->logo_url)->toBe('https://cdn/w.png')
        ->and($brand->retry_after->isAfter(now()->addDays(ResolveMerchantBrandJob::RESOLVED_RETRY_DAYS - 1)))->toBeTrue()
        ->and($row->fresh()->merchant_name)->toBeNull()
        ->and($row->fresh()->merchant_key)->toBe($keyBefore);
});

it('gives a partial profile a shorter retry window than a complete one', function () {
    woolworthsRow($this->user);
    $this->contextDev->shouldReceive('brandFromTransaction')->once()
        ->andReturn(new MerchantBrandData(title: 'Woolworths', partial: true));

    runResolve($this->user);

    $brand = MerchantBrand::query()->sole();

    expect($brand->partial)->toBeTrue()
        ->and($brand->retry_after->isBefore(now()->addDays(ResolveMerchantBrandJob::PARTIAL_RETRY_DAYS + 1)))->toBeTrue();
});

it('records an unresolved verdict and does not pay again inside its window', function () {
    woolworthsRow($this->user);
    $this->contextDev->shouldReceive('brandFromTransaction')->once()->andReturnNull();

    runResolve($this->user);
    runResolve($this->user);

    expect(MerchantBrand::query()->sole()->status)->toBe(MerchantBrandStatus::Unresolved);
});

it('looks a key up again once its retry window has passed', function () {
    woolworthsRow($this->user);
    MerchantBrand::factory()->for($this->user)->unresolved()->expired()->create(['merchant_key' => 'VISA WOOLWORTHS SYDNEY']);
    $this->contextDev->shouldReceive('brandFromTransaction')->once()->andReturn(new MerchantBrandData(title: 'Woolworths'));

    runResolve($this->user);

    expect(MerchantBrand::query()->sole()->status)->toBe(MerchantBrandStatus::Resolved);
});

it('makes no call when the lookup is blocked', function (Closure $arrange) {
    woolworthsRow($this->user);
    $arrange($this->user);
    $this->contextDev->shouldNotReceive('brandFromTransaction');

    runResolve($this->user);
})->with([
    'vetoed' => [fn (User $user) => MerchantBrand::factory()->for($user)->vetoed()->create(['merchant_key' => 'VISA WOOLWORTHS SYDNEY'])],
    'fresh resolved row' => [fn (User $user) => MerchantBrand::factory()->for($user)->create(['merchant_key' => 'VISA WOOLWORTHS SYDNEY'])],
    'kill switch off' => [fn () => config(['services.context_dev.enrichment_enabled' => false])],
    'daily cap spent' => [fn () => config(['services.context_dev.daily_credit_cap' => 5])],
]);

it('makes no call when every row for the key fails the gate', function () {
    woolworthsRow($this->user, ['direction' => TransactionDirection::Credit]);
    $this->contextDev->shouldNotReceive('brandFromTransaction');

    runResolve($this->user);

    expect(MerchantBrand::query()->count())->toBe(0);
});

it('does not overwrite a veto made while the lookup was in flight', function () {
    woolworthsRow($this->user);
    $this->contextDev->shouldReceive('brandFromTransaction')->once()->andReturnUsing(function () {
        MerchantBrand::factory()->for($this->user)->vetoed()->create(['merchant_key' => 'VISA WOOLWORTHS SYDNEY']);

        return new MerchantBrandData(title: 'Woolworths');
    });

    runResolve($this->user);

    expect(MerchantBrand::query()->sole()->status)->toBe(MerchantBrandStatus::Vetoed);
});

it('writes nothing when the API fails, leaving the key for the next sweep', function () {
    woolworthsRow($this->user);
    $this->contextDev->shouldReceive('brandFromTransaction')->once()
        ->andThrow(new APIConnectionException(new Request('POST', 'https://api.context.dev/v1/brand/retrieve')));

    runResolve($this->user);

    expect(MerchantBrand::query()->count())->toBe(0);
});

it('never reads another user\'s transactions for the key', function () {
    woolworthsRow(User::factory()->create());
    $this->contextDev->shouldNotReceive('brandFromTransaction');

    runResolve($this->user);
});
