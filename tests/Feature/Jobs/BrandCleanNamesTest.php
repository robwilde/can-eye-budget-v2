<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\ContextDevServiceContract;
use App\DTOs\MerchantBrandData;
use App\Enums\CleanDescriptionSource;
use App\Enums\MerchantBrandStatus;
use App\Enums\TransactionDirection;
use App\Jobs\EnrichMerchantBrandsJob;
use App\Jobs\ResolveMerchantBrandJob;
use App\Livewire\TransactionList;
use App\Models\MerchantBrand;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MerchantBrands\BrandNameWriter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'services.context_dev.enrichment_enabled' => true,
        'services.context_dev.daily_credit_cap' => 500,
    ]);

    $this->user = User::factory()->create();
    $this->contextDev = Mockery::mock(ContextDevServiceContract::class);
    app()->instance(ContextDevServiceContract::class, $this->contextDev);
});

/** @param  array<string, mixed>  $attributes */
function brandNamedRow(User $user, string $description, array $attributes = []): Transaction
{
    return Transaction::factory()->for($user)->create([
        'description' => $description,
        'direction' => TransactionDirection::Debit,
        'merchant_name' => null,
        'clean_description' => null,
        ...$attributes,
    ]);
}

test('a resolved brand names blank and derived rows but leaves deliberate and feed names', function () {
    $blank = brandNamedRow($this->user, 'VISA WOOLWORTHS 1234 SYDNEY');
    $derived = brandNamedRow($this->user, 'VISA WOOLWORTHS 5678 SYDNEY', [
        'clean_description' => 'Woolworths Sydney',
        'clean_description_source' => CleanDescriptionSource::Derived,
    ]);
    $feed = brandNamedRow($this->user, 'VISA WOOLWORTHS 9012 SYDNEY', [
        'clean_description' => 'Woolies Sydney',
        'clean_description_source' => CleanDescriptionSource::Feed,
    ]);
    $manual = brandNamedRow($this->user, 'VISA WOOLWORTHS 3456 SYDNEY', ['clean_description' => 'Weekly shop']);
    $rule = brandNamedRow($this->user, 'VISA WOOLWORTHS 7890 SYDNEY', [
        'clean_description' => 'Groceries',
        'clean_description_source' => CleanDescriptionSource::Rule,
    ]);
    $other = brandNamedRow($this->user, 'NETFLIX.COM');
    $key = $blank->merchant_key;

    $this->contextDev->shouldReceive('brandFromTransaction')->once()
        ->andReturn(new MerchantBrandData(title: 'Woolworths', domain: 'woolworths.com.au'));

    dispatch_sync(new ResolveMerchantBrandJob($this->user, $key));

    expect($blank->fresh()->clean_description)->toBe('Woolworths')
        ->and($blank->fresh()->clean_description_source)->toBe(CleanDescriptionSource::Brand)
        ->and($derived->fresh()->clean_description)->toBe('Woolworths')
        ->and($derived->fresh()->clean_description_source)->toBe(CleanDescriptionSource::Brand)
        ->and($feed->fresh()->clean_description)->toBe('Woolies Sydney')
        ->and($manual->fresh()->clean_description)->toBe('Weekly shop')
        ->and($rule->fresh()->clean_description)->toBe('Groceries')
        ->and($other->fresh()->clean_description)->toBeNull();
});

test('merchant keys stay stable when a brand names the rows', function () {
    $rows = collect(['VISA WOOLWORTHS 1234 SYDNEY', 'VISA WOOLWORTHS 5678 SYDNEY'])
        ->map(fn (string $description): Transaction => brandNamedRow($this->user, $description));
    $keys = $rows->map(fn (Transaction $row): string => $row->merchant_key)->all();

    $this->contextDev->shouldReceive('brandFromTransaction')->once()
        ->andReturn(new MerchantBrandData(title: 'Woolworths Group'));

    dispatch_sync(new ResolveMerchantBrandJob($this->user, $keys[0]));

    expect($rows->map(fn (Transaction $row): string => $row->fresh()->merchant_key)->all())->toBe($keys)
        ->and($rows->first()->fresh()->clean_description)->toBe('Woolworths Group');
});

test('a partial or unresolved brand names no rows', function (Closure $answer) {
    $row = brandNamedRow($this->user, 'VISA WOOLWORTHS 1234 SYDNEY');

    $this->contextDev->shouldReceive('brandFromTransaction')->andReturnUsing($answer);

    dispatch_sync(new ResolveMerchantBrandJob($this->user, $row->merchant_key));

    expect($row->fresh()->clean_description)->toBeNull()
        ->and($row->fresh()->clean_description_source)->toBeNull();
})->with([
    'partial' => [fn () => new MerchantBrandData(title: 'Woolworths', partial: true)],
    'unresolved' => [fn () => null],
]);

test('names are filled only for the lookups the daily credit budget allows', function () {
    config(['services.context_dev.daily_credit_cap' => 10]);

    $busy = collect(range(1, 3))->map(fn (int $n): Transaction => brandNamedRow($this->user, "VISA WOOLWORTHS 100{$n} SYDNEY"));
    $quiet = collect(range(1, 2))->map(fn (int $n): Transaction => brandNamedRow($this->user, "NETFLIX.COM {$n}"));

    $this->contextDev->shouldReceive('brandFromTransaction')->once()
        ->andReturn(new MerchantBrandData(title: 'Woolworths'));

    dispatch_sync(new EnrichMerchantBrandsJob($this->user));

    expect($busy->map(fn (Transaction $row): ?string => $row->fresh()->clean_description)->unique()->all())->toBe(['Woolworths'])
        ->and($quiet->map(fn (Transaction $row): ?string => $row->fresh()->clean_description)->unique()->all())->toBe([null]);
});

test('vetoing the merchant returns brand names to the derived name', function () {
    config(['services.context_dev.enrichment_enabled' => false]);

    $branded = brandNamedRow($this->user, 'VISA WOOLWORTHS 1234 SYDNEY', [
        'clean_description' => 'Woolworths Group',
        'clean_description_source' => CleanDescriptionSource::Brand,
    ]);
    $manual = brandNamedRow($this->user, 'VISA WOOLWORTHS 5678 SYDNEY', ['clean_description' => 'Weekly shop']);
    $otherMerchant = brandNamedRow($this->user, 'AUTHORISATION', [
        'clean_description' => 'Mystery brand',
        'clean_description_source' => CleanDescriptionSource::Brand,
    ]);
    MerchantBrand::factory()->for($this->user)->create(['merchant_key' => $branded->merchant_key]);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->call('vetoMerchantBrand', $branded->id);

    expect(MerchantBrand::query()->sole()->status)->toBe(MerchantBrandStatus::Vetoed)
        ->and($branded->fresh()->clean_description)->toBe('Woolworths Sydney')
        ->and($branded->fresh()->clean_description_source)->toBe(CleanDescriptionSource::Derived)
        ->and($manual->fresh()->clean_description)->toBe('Weekly shop')
        ->and($manual->fresh()->clean_description_source)->toBe(CleanDescriptionSource::Manual)
        ->and($otherMerchant->fresh()->clean_description)->toBe('Mystery brand');
});

test('a brand names a row without a name from the Redbark merchant name, not the brand title', function () {
    $row = brandNamedRow($this->user, 'VISA WOOLWORTHS 1234 SYDNEY', ['merchant_name' => 'Woolworths Metro']);

    $this->contextDev->shouldReceive('brandFromTransaction')->once()
        ->andReturn(new MerchantBrandData(title: 'Woolworths Group'));

    dispatch_sync(new ResolveMerchantBrandJob($this->user, $row->merchant_key));

    expect($row->fresh()->clean_description)->toBe('Woolworths Metro')
        ->and($row->fresh()->clean_description_source)->toBe(CleanDescriptionSource::Feed);
});

test('vetoing the merchant returns a brand name to the Redbark merchant name', function () {
    config(['services.context_dev.enrichment_enabled' => false]);

    $branded = brandNamedRow($this->user, 'VISA WOOLWORTHS 1234 SYDNEY', [
        'merchant_name' => 'Woolworths Metro',
        'clean_description' => 'Woolworths Group',
        'clean_description_source' => CleanDescriptionSource::Brand,
    ]);
    MerchantBrand::factory()->for($this->user)->create(['merchant_key' => $branded->merchant_key]);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->call('vetoMerchantBrand', $branded->id);

    expect($branded->fresh()->clean_description)->toBe('Woolworths Metro')
        ->and($branded->fresh()->clean_description_source)->toBe(CleanDescriptionSource::Feed);
});

test('a legacy brand name is corrected to the Redbark merchant name even when the titles match', function () {
    $row = brandNamedRow($this->user, 'VISA WOOLWORTHS 1234 SYDNEY', [
        'merchant_name' => 'Woolworths',
        'clean_description' => 'Woolworths',
        'clean_description_source' => CleanDescriptionSource::Brand,
    ]);

    app(BrandNameWriter::class)->fill($this->user, $row->merchant_key, 'Woolworths');

    expect($row->fresh()->clean_description)->toBe('Woolworths')
        ->and($row->fresh()->clean_description_source)->toBe(CleanDescriptionSource::Feed);
});

test('a merchant vetoed while the lookup is in flight names no rows', function () {
    $row = brandNamedRow($this->user, 'VISA WOOLWORTHS 1234 SYDNEY');
    $key = $row->merchant_key;

    $this->contextDev->shouldReceive('brandFromTransaction')->once()->andReturnUsing(function () use ($key): MerchantBrandData {
        MerchantBrand::query()->updateOrCreate(
            ['user_id' => $this->user->id, 'merchant_key' => $key],
            ['status' => MerchantBrandStatus::Vetoed, 'retry_after' => null],
        );

        return new MerchantBrandData(title: 'Woolworths');
    });

    dispatch_sync(new ResolveMerchantBrandJob($this->user, $key));

    expect($row->fresh()->clean_description)->toBeNull();
});

test('a cached brand names later imports without a new lookup or any budget', function (int $cap) {
    config(['services.context_dev.daily_credit_cap' => $cap]);

    $row = brandNamedRow($this->user, 'VISA WOOLWORTHS 1234 SYDNEY');
    MerchantBrand::factory()->for($this->user)->create([
        'merchant_key' => $row->merchant_key,
        'title' => 'Woolworths',
        'partial' => false,
        'retry_after' => now()->addDays(100),
    ]);
    $later = brandNamedRow($this->user, 'VISA WOOLWORTHS 5678 SYDNEY');

    $this->contextDev->shouldNotReceive('brandFromTransaction');

    dispatch_sync(new EnrichMerchantBrandsJob($this->user));

    expect($later->fresh()->clean_description)->toBe('Woolworths')
        ->and($later->fresh()->clean_description_source)->toBe(CleanDescriptionSource::Brand);
})->with([
    'budget left' => [500],
    'budget spent' => [0],
]);

test('a name a higher-priority writer set after the rows were read is never replaced', function () {
    $row = brandNamedRow($this->user, 'VISA WOOLWORTHS 1234 SYDNEY');
    $key = $row->merchant_key;
    $raced = false;

    Transaction::retrieved(function (Transaction $loaded) use (&$raced): void {
        if ($raced) {
            return;
        }

        $raced = true;
        DB::table('transactions')->where('id', $loaded->id)->update([
            'clean_description' => 'Groceries',
            'clean_description_source' => CleanDescriptionSource::Rule->value,
        ]);
    });

    app(BrandNameWriter::class)->fill($this->user, $key, 'Woolworths');

    expect($row->fresh()->clean_description)->toBe('Groceries')
        ->and($row->fresh()->clean_description_source)->toBe(CleanDescriptionSource::Rule);
});

test('a name a higher-priority writer set after the rows were read is never reverted', function () {
    $row = brandNamedRow($this->user, 'VISA WOOLWORTHS 1234 SYDNEY', [
        'clean_description' => 'Woolworths Group',
        'clean_description_source' => CleanDescriptionSource::Brand,
    ]);
    $key = $row->merchant_key;
    $raced = false;

    Transaction::retrieved(function (Transaction $loaded) use (&$raced): void {
        if ($raced) {
            return;
        }

        $raced = true;
        DB::table('transactions')->where('id', $loaded->id)->update([
            'clean_description' => 'Weekly shop',
            'clean_description_source' => CleanDescriptionSource::Manual->value,
        ]);
    });

    app(BrandNameWriter::class)->revoke($this->user, $key);

    expect($row->fresh()->clean_description)->toBe('Weekly shop')
        ->and($row->fresh()->clean_description_source)->toBe(CleanDescriptionSource::Manual);
});

test('resolved brands are filled only while the sidecar is still resolved under the lock', function () {
    $row = brandNamedRow($this->user, 'VISA WOOLWORTHS 1234 SYDNEY');
    $key = $row->merchant_key;
    MerchantBrand::factory()->for($this->user)->create([
        'merchant_key' => $key,
        'status' => MerchantBrandStatus::Resolved,
        'partial' => false,
        'title' => 'Woolworths',
    ]);
    $vetoed = false;

    MerchantBrand::retrieved(function (MerchantBrand $loaded) use (&$vetoed): void {
        if ($vetoed) {
            return;
        }

        $vetoed = true;
        DB::table('merchant_brands')->where('id', $loaded->id)->update(['status' => MerchantBrandStatus::Vetoed->value]);
    });

    app(BrandNameWriter::class)->fillFromResolvedBrands($this->user);

    expect($row->fresh()->clean_description)->toBeNull();
});

test('resolved brands name the rows that carry them', function () {
    $row = brandNamedRow($this->user, 'VISA WOOLWORTHS 1234 SYDNEY');
    MerchantBrand::factory()->for($this->user)->create([
        'merchant_key' => $row->merchant_key,
        'status' => MerchantBrandStatus::Resolved,
        'partial' => false,
        'title' => 'Woolworths',
    ]);

    app(BrandNameWriter::class)->fillFromResolvedBrands($this->user);

    expect($row->fresh()->clean_description)->toBe('Woolworths')
        ->and($row->fresh()->clean_description_source)->toBe(CleanDescriptionSource::Brand);
});

test('a resync leaves rows that already carry the brand name unsaved and still refreshes a changed title', function () {
    $rows = collect(['VISA WOOLWORTHS 1234 SYDNEY', 'VISA WOOLWORTHS 5678 SYDNEY'])
        ->map(fn (string $description): Transaction => brandNamedRow($this->user, $description, [
            'clean_description' => 'Woolworths',
            'clean_description_source' => CleanDescriptionSource::Brand,
        ]));
    $brand = MerchantBrand::factory()->for($this->user)->create([
        'merchant_key' => $rows->first()->merchant_key,
        'status' => MerchantBrandStatus::Resolved,
        'partial' => false,
        'title' => 'Woolworths',
    ]);

    $lockedReads = 0;
    $updates = 0;
    DB::listen(function (QueryExecuted $query) use (&$lockedReads, &$updates): void {
        if (preg_match('/^\\s*update\\s+[`"]?transactions[`"]?\\s/i', $query->sql) === 1) {
            $updates++;
        }

        if (preg_match('/from\\s+[`"]?transactions[`"]?\\s+where.*[`"]id[`"]\\s*=\\s*\\?/is', $query->sql) === 1) {
            $lockedReads++;
        }
    });

    app(BrandNameWriter::class)->fillFromResolvedBrands($this->user);

    expect($lockedReads)->toBe(0)
        ->and($updates)->toBe(0);

    $brand->update(['title' => 'Woolworths Group']);

    app(BrandNameWriter::class)->fillFromResolvedBrands($this->user);

    expect($lockedReads)->toBe(2)
        ->and($updates)->toBe(2)
        ->and($rows->map(fn (Transaction $row): ?string => $row->fresh()->clean_description)->unique()->all())->toBe(['Woolworths Group']);
});

test('a vetoed brand name is removed from a deleted row before a resync restores it', function () {
    $branded = brandNamedRow($this->user, 'VISA WOOLWORTHS 1234 SYDNEY', [
        'clean_description' => 'Woolworths Group',
        'clean_description_source' => CleanDescriptionSource::Brand,
    ]);
    $branded->delete();

    app(BrandNameWriter::class)->revoke($this->user, $branded->merchant_key);

    $branded->restore();

    expect($branded->fresh()->clean_description)->toBe('Woolworths Sydney')
        ->and($branded->fresh()->clean_description_source)->toBe(CleanDescriptionSource::Derived);
});

test('a vetoed brand name stays on a folded fee the sync never restores', function () {
    $parent = brandNamedRow($this->user, 'VISA WOOLWORTHS 1234 SYDNEY');
    $fee = brandNamedRow($this->user, 'VISA WOOLWORTHS 1234 SYDNEY', [
        'clean_description' => 'Woolworths Group',
        'clean_description_source' => CleanDescriptionSource::Brand,
        'folded_into_transaction_id' => $parent->id,
    ]);
    $fee->delete();

    app(BrandNameWriter::class)->revoke($this->user, $fee->merchant_key);

    expect(Transaction::withTrashed()->find($fee->id)->clean_description)->toBe('Woolworths Group');
});

test('a tab-only name is stored as blank and a cached brand names the row', function () {
    $row = brandNamedRow($this->user, 'VISA WOOLWORTHS 1234 SYDNEY', ['clean_description' => "\t"]);

    expect($row->fresh()->clean_description)->toBeNull()
        ->and($row->fresh()->clean_description_source)->toBeNull();

    MerchantBrand::factory()->for($this->user)->create([
        'merchant_key' => $row->merchant_key,
        'status' => MerchantBrandStatus::Resolved,
        'partial' => false,
        'title' => 'Woolworths',
    ]);

    app(BrandNameWriter::class)->fillFromResolvedBrands($this->user);

    expect($row->fresh()->clean_description)->toBe('Woolworths')
        ->and($row->fresh()->clean_description_source)->toBe(CleanDescriptionSource::Brand);
});

test('the revoke candidate scan is a locking read inside the veto transaction', function () {
    $row = brandNamedRow($this->user, 'VISA WOOLWORTHS 1234 SYDNEY', [
        'clean_description' => 'Woolworths Group',
        'clean_description_source' => CleanDescriptionSource::Brand,
    ]);
    $baseline = DB::transactionLevel();
    $levels = [];

    DB::listen(function (QueryExecuted $query) use (&$levels): void {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, '"clean_description_source" = ')) {
            $levels[] = DB::transactionLevel();
        }
    });

    app(BrandNameWriter::class)->revoke($this->user, $row->merchant_key);

    expect($levels)->not->toBeEmpty()
        ->and(min($levels))->toBeGreaterThan($baseline);
});
