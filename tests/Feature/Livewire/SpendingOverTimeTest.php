<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Livewire\SpendingOverTime;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

test('component renders for authenticated user', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SpendingOverTime::class)
        ->assertSuccessful();
});

test('calculates net of debits and credits', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -5000,
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'amount' => 2000,
        'post_date' => now()->subDays(5),
    ]);

    $component = Livewire::actingAs($user)
        ->test(SpendingOverTime::class);

    $data = $component->get('timeSeriesData');
    $nonZeroTotals = collect($data)->pluck('total')->filter(fn (int $t) => $t !== 0)->values();

    expect($nonZeroTotals)->toHaveCount(1)
        ->and($nonZeroTotals->first())->toBe(-3000);
});

test('credits exceeding debits produce positive net', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -2000,
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'amount' => 8000,
        'post_date' => now()->subDays(5),
    ]);

    $component = Livewire::actingAs($user)
        ->test(SpendingOverTime::class);

    $data = $component->get('timeSeriesData');
    $nonZeroTotals = collect($data)->pluck('total')->filter(fn (int $t) => $t !== 0)->values();

    expect($nonZeroTotals)->toHaveCount(1)
        ->and($nonZeroTotals->first())->toBe(6000);
});

test('shows zero when debits equal credits', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -5000,
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'amount' => 5000,
        'post_date' => now()->subDays(5),
    ]);

    $component = Livewire::actingAs($user)
        ->test(SpendingOverTime::class);

    $data = $component->get('timeSeriesData');
    $dayEntry = collect($data)->firstWhere('date', now()->subDays(5)->format('Y-m-d'));

    expect($dayEntry['total'])->toBe(0);
});

test('includes account names in time series data', function () {
    $user = User::factory()->create();
    $savings = Account::factory()->for($user)->create(['name' => 'CBA Savings']);
    $everyday = Account::factory()->for($user)->create(['name' => 'ANZ Everyday']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $savings->id,
        'amount' => -3000,
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $everyday->id,
        'amount' => -2000,
        'post_date' => now()->subDays(5),
    ]);

    $component = Livewire::actingAs($user)
        ->test(SpendingOverTime::class);

    $data = $component->get('timeSeriesData');
    $dayEntry = collect($data)->firstWhere('date', now()->subDays(5)->format('Y-m-d'));

    expect($dayEntry['accounts'])->toHaveCount(2)
        ->and($dayEntry['total'])->toBe(-5000);

    $accountNames = collect($dayEntry['accounts'])->pluck('name')->sort()->values();
    expect($accountNames->all())->toBe(['ANZ Everyday', 'CBA Savings']);
});

test('only shows current user transactions', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $otherAccount = Account::factory()->for($otherUser)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -3000,
        'post_date' => now()->subDays(5),
    ]);

    Transaction::factory()->for($otherUser)->debit()->create([
        'account_id' => $otherAccount->id,
        'amount' => -8000,
        'post_date' => now()->subDays(5),
    ]);

    $component = Livewire::actingAs($user)
        ->test(SpendingOverTime::class);

    $data = $component->get('timeSeriesData');
    $nonZeroTotals = collect($data)->pluck('total')->filter(fn (int $t) => $t < 0)->values();

    expect($nonZeroTotals)->toHaveCount(1)
        ->and($nonZeroTotals->first())->toBe(-3000);
});

test('aggregates daily for 7d period', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -2000,
        'post_date' => now()->subDays(3),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -3000,
        'post_date' => now()->subDays(3),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -1000,
        'post_date' => now()->subDays(1),
    ]);

    $component = Livewire::actingAs($user)
        ->test(SpendingOverTime::class)
        ->set('period', '7d');

    $data = $component->get('timeSeriesData');
    $nonZero = collect($data)->filter(fn (array $item) => $item['total'] !== 0);

    expect($nonZero)->toHaveCount(2);

    $dayThreeAgo = $nonZero->firstWhere('date', now()->subDays(3)->format('Y-m-d'));
    expect($dayThreeAgo['total'])->toBe(-5000)
        ->and($dayThreeAgo['accounts'])->toBeArray();
});

test('aggregates daily for 30d period', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -4000,
        'post_date' => now()->subDays(15),
    ]);

    $component = Livewire::actingAs($user)
        ->test(SpendingOverTime::class)
        ->set('period', '30d');

    $data = $component->get('timeSeriesData');
    $entry = collect($data)->firstWhere('date', now()->subDays(15)->format('Y-m-d'));

    expect($data)->toHaveCount(31)
        ->and($entry['total'])->toBe(-4000)
        ->and($entry['accounts'])->toBeArray();
});

test('aggregates weekly for 90d period', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $monday = now()->subWeeks(4)->startOfWeek();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -2000,
        'post_date' => $monday,
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -3000,
        'post_date' => $monday->copy()->addDays(2),
    ]);

    $component = Livewire::actingAs($user)
        ->test(SpendingOverTime::class)
        ->set('period', '90d');

    $data = $component->get('timeSeriesData');
    $weekEntry = collect($data)->firstWhere('date', $monday->format('Y-m-d'));

    expect($weekEntry)->not->toBeNull()
        ->and($weekEntry['total'])->toBe(-5000)
        ->and($weekEntry['accounts'])->toBeArray();
});

test('aggregates monthly for 12m period', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $monthStart = now()->subMonths(3)->startOfMonth();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -6000,
        'post_date' => $monthStart->copy()->addDays(5),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -4000,
        'post_date' => $monthStart->copy()->addDays(10),
    ]);

    $component = Livewire::actingAs($user)
        ->test(SpendingOverTime::class)
        ->set('period', '12m');

    $data = $component->get('timeSeriesData');
    $monthEntry = collect($data)->firstWhere('date', $monthStart->format('Y-m-01'));

    expect($monthEntry)->not->toBeNull()
        ->and($monthEntry['total'])->toBe(-10000)
        ->and($monthEntry['accounts'])->toBeArray();
});

test('fills zero values for missing periods', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -1000,
        'post_date' => now()->subDays(1),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -2000,
        'post_date' => now()->subDays(5),
    ]);

    $component = Livewire::actingAs($user)
        ->test(SpendingOverTime::class)
        ->set('period', '7d');

    $data = $component->get('timeSeriesData');

    expect($data)->toHaveCount(8);

    $zeroEntries = collect($data)->filter(fn (array $item) => $item['total'] === 0);
    expect($zeroEntries)->toHaveCount(6);

    $zeroEntry = $zeroEntries->first();
    expect($zeroEntry['accounts'])->toBe([]);
});

test('period selector filters by date range', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -2000,
        'post_date' => now()->subDays(3),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -5000,
        'post_date' => now()->subDays(20),
    ]);

    $component = Livewire::actingAs($user)
        ->test(SpendingOverTime::class)
        ->set('period', '7d');

    $data = $component->get('timeSeriesData');
    $nonZeroTotals = collect($data)->pluck('total')->filter(fn (int $t) => $t < 0);

    expect($nonZeroTotals)->toHaveCount(1)
        ->and($nonZeroTotals->first())->toBe(-2000);
});

test('changing period dispatches spending-over-time-updated event', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SpendingOverTime::class)
        ->set('period', '90d')
        ->assertDispatched('spending-over-time-updated');
});

test('empty state when no transactions exist', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SpendingOverTime::class)
        ->assertSee('No spending data');
});
