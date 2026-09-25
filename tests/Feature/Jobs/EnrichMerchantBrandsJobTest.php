<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Jobs\EnrichMerchantBrandsJob;
use App\Jobs\ResolveMerchantBrandJob;
use App\Models\MerchantBrand;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'services.context_dev.enrichment_enabled' => true,
        'services.context_dev.daily_credit_cap' => 500,
    ]);

    Queue::fake([ResolveMerchantBrandJob::class]);
    $this->user = User::factory()->create();
});

function rows(User $user, string $description, int $count, array $attributes = []): void
{
    Transaction::factory()->for($user)->count($count)->create([
        'description' => $description,
        'direction' => TransactionDirection::Debit,
        'merchant_name' => null,
        ...$attributes,
    ]);
}

/** @return list<string> */
function queuedKeys(): array
{
    return Queue::pushed(ResolveMerchantBrandJob::class)
        ->map(fn (ResolveMerchantBrandJob $job): string => $job->merchantKey)
        ->sort()->values()->all();
}

it('queues one lookup per recurring merchant key, not per row', function () {
    rows($this->user, 'WOOLWORTHS 1234 SYDNEY', 3);
    rows($this->user, 'NETFLIX.COM', 2);

    dispatch_sync(new EnrichMerchantBrandsJob($this->user));

    expect(queuedKeys())->toBe(['NETFLIX.COM', 'WOOLWORTHS SYDNEY']);
    Queue::assertPushedOn(ResolveMerchantBrandJob::QUEUE, ResolveMerchantBrandJob::class);
});

it('skips one-off, credit, transfer, vetoed and fresh keys', function () {
    rows($this->user, 'ONE OFF MARKET STALL', 1);
    rows($this->user, 'SALARY ACME PTY LTD', 2, ['direction' => TransactionDirection::Credit]);
    rows($this->user, 'BUNNINGS WAREHOUSE ADELAIDE', 2, ['transfer_pair_id' => Transaction::factory()->for($this->user)->credit()]);
    rows($this->user, 'KMART AUSTRALIA PERTH', 2);
    rows($this->user, 'ALDI STORES BRISBANE', 2);
    rows($this->user, 'COLES SUPERMARKET MELBOURNE', 2);
    MerchantBrand::factory()->for($this->user)->vetoed()->create(['merchant_key' => 'KMART AUSTRALIA PERTH']);
    MerchantBrand::factory()->for($this->user)->unresolved()->create(['merchant_key' => 'ALDI STORES BRISBANE']);

    dispatch_sync(new EnrichMerchantBrandsJob($this->user));

    expect(queuedKeys())->toBe(['COLES SUPERMARKET MELBOURNE']);
});

it('retries keys whose window has expired', function () {
    rows($this->user, 'ALDI STORES BRISBANE', 2);
    MerchantBrand::factory()->for($this->user)->unresolved()->expired()->create(['merchant_key' => 'ALDI STORES BRISBANE']);

    dispatch_sync(new EnrichMerchantBrandsJob($this->user));

    expect(queuedKeys())->toBe(['ALDI STORES BRISBANE']);
});

it('sizes the batch to the remaining budget, most frequent first', function () {
    config(['services.context_dev.daily_credit_cap' => 20]);
    rows($this->user, 'WOOLWORTHS 1234 SYDNEY', 5);
    rows($this->user, 'NETFLIX.COM', 4);
    rows($this->user, 'KMART AUSTRALIA PERTH', 2);

    dispatch_sync(new EnrichMerchantBrandsJob($this->user));

    expect(queuedKeys())->toBe(['NETFLIX.COM', 'WOOLWORTHS SYDNEY']);
});

it('queues nothing when enrichment is switched off', function () {
    config(['services.context_dev.enrichment_enabled' => false]);
    rows($this->user, 'WOOLWORTHS 1234 SYDNEY', 3);

    dispatch_sync(new EnrichMerchantBrandsJob($this->user));

    Queue::assertNothingPushed();
});

it('only considers the user\'s own transactions', function () {
    rows(User::factory()->create(), 'WOOLWORTHS 1234 SYDNEY', 3);

    dispatch_sync(new EnrichMerchantBrandsJob($this->user));

    Queue::assertNothingPushed();
});
