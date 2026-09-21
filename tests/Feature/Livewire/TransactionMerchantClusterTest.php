<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Livewire\TransactionList;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function clusterTxn(User $user, Account $account, string $description, array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => $description,
        'merchant_name' => null,
        'clean_description' => null,
        'category_id' => null,
        'amount' => 1000,
        'direction' => TransactionDirection::Debit,
        'post_date' => CarbonImmutable::parse('2026-06-10'),
    ], $overrides));
}

it('orders clusters by size and collapses reference numbers into one cluster', function () {
    // Two PayPal Steam rows with different reference numbers: the whole point of
    // merchant_key is that these land together despite being different strings.
    clusterTxn($this->user, $this->account, 'PAYPAL *STEAM 4829');
    clusterTxn($this->user, $this->account, 'PAYPAL *STEAM 5561');
    clusterTxn($this->user, $this->account, 'PAYPAL *STEAM 9903');
    clusterTxn($this->user, $this->account, 'NETFLIX.COM');

    $clusters = Livewire::actingAs($this->user)
        ->test(TransactionList::class, ['categorised' => 'uncategorised'])
        ->set('categorised', 'uncategorised')
        ->set('groupMode', 'merchant')
        ->viewData('clusters');

    $keys = collect($clusters->items())->pluck('merchant_key')->all();
    $counts = collect($clusters->items())->pluck('row_count', 'merchant_key')->all();

    expect($keys[0])->toBe('PAYPAL STEAM')
        ->and((int) $counts['PAYPAL STEAM'])->toBe(3)
        ->and((int) $counts['NETFLIX.COM'])->toBe(1);
});

it('reports the amount spread, account count and direction mix per cluster', function () {
    $secondAccount = Account::factory()->for($this->user)->create();

    clusterTxn($this->user, $this->account, 'AFTERPAY', ['amount' => 1200]);
    clusterTxn($this->user, $this->account, 'AFTERPAY', ['amount' => 90000]);
    clusterTxn($this->user, $secondAccount, 'AFTERPAY', [
        'amount' => 5000,
        'direction' => TransactionDirection::Credit,
    ]);

    $clusters = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->set('groupMode', 'merchant')
        ->viewData('clusters');

    $cluster = collect($clusters->items())->firstWhere('merchant_key', 'AFTERPAY');

    expect((int) $cluster->row_count)->toBe(3)
        ->and((int) $cluster->min_amount)->toBe(1200)
        ->and((int) $cluster->max_amount)->toBe(90000)
        ->and((int) $cluster->account_count)->toBe(2)
        ->and((int) $cluster->direction_count)->toBe(2);
});

it('loads member rows only for the expanded cluster, one at a time', function () {
    clusterTxn($this->user, $this->account, 'NETFLIX.COM');
    clusterTxn($this->user, $this->account, 'SPOTIFY AB');

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->set('groupMode', 'merchant');

    expect($component->viewData('clusterRows'))->toHaveCount(0);

    $component->call('toggleCluster', 'NETFLIX.COM');
    expect($component->viewData('clusterRows')->pluck('merchant_key')->unique()->all())
        ->toBe(['NETFLIX.COM']);

    // Opening a second cluster closes the first: one expansion at a time.
    $component->call('toggleCluster', 'SPOTIFY AB');
    expect($component->get('expandedKey'))->toBe('SPOTIFY AB')
        ->and($component->viewData('clusterRows')->pluck('merchant_key')->unique()->all())
        ->toBe(['SPOTIFY AB']);

    // Re-clicking the open cluster collapses it.
    $component->call('toggleCluster', 'SPOTIFY AB');
    expect($component->get('expandedKey'))->toBeNull()
        ->and($component->viewData('clusterRows'))->toHaveCount(0);
});

it('applies the account filter identically in cluster mode', function () {
    $other = Account::factory()->for($this->user)->create();

    clusterTxn($this->user, $this->account, 'NETFLIX.COM');
    clusterTxn($this->user, $other, 'SPOTIFY AB');

    $clusters = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->set('groupMode', 'merchant')
        ->set('account', $this->account->id)
        ->viewData('clusters');

    expect(collect($clusters->items())->pluck('merchant_key')->all())->toBe(['NETFLIX.COM']);
});

it('never clusters another users transactions', function () {
    $intruder = User::factory()->create();
    $intruderAccount = Account::factory()->for($intruder)->create();

    clusterTxn($this->user, $this->account, 'NETFLIX.COM');
    clusterTxn($intruder, $intruderAccount, 'SECRET MERCHANT');

    $clusters = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->set('groupMode', 'merchant')
        ->viewData('clusters');

    expect(collect($clusters->items())->pluck('merchant_key')->all())->toBe(['NETFLIX.COM']);
});

it('excludes categorised rows from clusters when filtering uncategorised', function () {
    $category = App\Models\Category::factory()->create(['is_hidden' => false]);

    clusterTxn($this->user, $this->account, 'NETFLIX.COM');
    clusterTxn($this->user, $this->account, 'ALREADY DONE', ['category_id' => $category->id]);

    $clusters = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->set('groupMode', 'merchant')
        ->viewData('clusters');

    expect(collect($clusters->items())->pluck('merchant_key')->all())->toBe(['NETFLIX.COM']);
});

it('keeps the date-grouped view untouched in date mode', function () {
    clusterTxn($this->user, $this->account, 'NETFLIX.COM');

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('groupMode', 'date');

    expect($component->viewData('clusters'))->toBeNull()
        ->and($component->viewData('grouped'))->not->toBeEmpty()
        ->and($component->viewData('transactions'))->not->toBeNull();
});

it('does not issue a query per cluster when rendering a page of clusters', function () {
    foreach (['NETFLIX.COM', 'SPOTIFY AB', 'AFTERPAY', 'UBER TRIP', 'COLES SUPERMARKET'] as $merchant) {
        clusterTxn($this->user, $this->account, $merchant);
        clusterTxn($this->user, $this->account, $merchant);
    }

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->set('groupMode', 'merchant');

    DB::enableQueryLog();
    DB::flushQueryLog();

    $component->call('$refresh');

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Five clusters render. A per-cluster query would push this well past the
    // fixed set of page queries; the point is that cluster count does not
    // drive query count.
    expect($queries)->toBeLessThan(15);
});

it('falls back to date mode for an unknown group mode and clears a stale expansion', function () {
    clusterTxn($this->user, $this->account, 'NETFLIX.COM');

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class, ['groupMode' => 'nonsense', 'expandedKey' => 'NETFLIX.COM']);

    expect($component->get('groupMode'))->toBe('date')
        ->and($component->get('expandedKey'))->toBeNull();
});

it('clears the expanded cluster when switching modes', function () {
    clusterTxn($this->user, $this->account, 'NETFLIX.COM');

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->set('groupMode', 'merchant')
        ->call('toggleCluster', 'NETFLIX.COM');

    expect($component->get('expandedKey'))->toBe('NETFLIX.COM');

    $component->set('groupMode', 'date');

    expect($component->get('expandedKey'))->toBeNull();
});
