<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\ContextDevServiceContract;
use App\Enums\TransactionDirection;
use App\Jobs\ResolveMerchantBrandJob;
use App\Livewire\TransactionList;
use App\Models\MerchantBrand;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00'));
    config(['services.context_dev.enrichment_enabled' => true]);
    Queue::fake([ResolveMerchantBrandJob::class]);

    $contextDev = Mockery::mock(ContextDevServiceContract::class);
    $contextDev->allows('creditsRemaining')->andReturn(970);
    app()->instance(ContextDevServiceContract::class, $contextDev);

    $this->user = User::factory()->create();
    $this->row = fn (string $description): Transaction => Transaction::factory()->for($this->user)->create([
        'description' => $description,
        'direction' => TransactionDirection::Debit,
        'merchant_name' => null,
        'post_date' => now()->subDays(2),
    ]);
});

function seedVisibleMerchants(Closure $row): array
{
    $woolworths = $row('VISA WOOLWORTHS SYDNEY');
    $row('VISA WOOLWORTHS SYDNEY');
    $jetBrains = $row('VISA -JetBrains Prague CZ');

    return [$woolworths->merchant_key, $jetBrains->merchant_key];
}

it('offers one lookup per distinct on-screen merchant and queues exactly those', function () {
    $keys = seedVisibleMerchants($this->row);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->assertSeeHtml('data-testid="update-visible-merchants"')
        ->assertSee('Update 2 merchants on this page (≤20 credits)')
        ->call('updateVisibleMerchants')
        ->assertSee('Identifying 2…');

    Queue::assertPushed(ResolveMerchantBrandJob::class, 2);
    expect(array_keys($component->get('pendingMerchantKeys')))->toEqualCanonicalizing($keys);
});

it('queues only what today\'s allowance covers', function () {
    config(['services.context_dev.daily_credit_cap' => 10]);
    seedVisibleMerchants($this->row);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->call('updateVisibleMerchants')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => str_contains($params['slots']['text'], '1 skipped'));

    Queue::assertPushed(ResolveMerchantBrandJob::class, 1);
});

it('queues nothing once the allowance is spent', function () {
    config(['services.context_dev.daily_credit_cap' => 0]);
    seedVisibleMerchants($this->row);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->call('updateVisibleMerchants');

    Queue::assertNothingPushed();
});

it('offers nothing when every on-screen row is resolved or fails the gate', function () {
    $resolved = ($this->row)('VISA WOOLWORTHS SYDNEY');
    MerchantBrand::factory()->for($this->user)->create(['merchant_key' => $resolved->merchant_key]);
    ($this->row)('TRANSFER TO J SMITH');

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->assertDontSeeHtml('data-testid="update-visible-merchants"')
        ->call('updateVisibleMerchants');

    Queue::assertNothingPushed();
});

it('polls while lookups are pending, dropping keys as their brands arrive', function () {
    [$woolworths, $jetBrains] = seedVisibleMerchants($this->row);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->assertDontSeeHtml('wire:poll.4s="pollMerchantBrands"')
        ->call('updateVisibleMerchants')
        ->assertSeeHtml('wire:poll.4s="pollMerchantBrands"');

    MerchantBrand::factory()->for($this->user)->create(['merchant_key' => $woolworths]);

    $component->call('pollMerchantBrands');

    expect(array_keys($component->get('pendingMerchantKeys')))->toBe([$jetBrains]);
});

it('keeps a re-queued key pending until its expired row is rewritten', function () {
    $row = ($this->row)('VISA WOOLWORTHS SYDNEY');
    $stale = MerchantBrand::factory()->for($this->user)->unresolved()->expired()->create(['merchant_key' => $row->merchant_key]);
    $this->travel(1)->minutes();

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->call('updateVisibleMerchants')
        ->call('pollMerchantBrands');

    expect(array_keys($component->get('pendingMerchantKeys')))->toBe([$row->merchant_key]);

    $stale->update(['retry_after' => now()->addDays(14)]);

    $component->call('pollMerchantBrands');

    expect($component->get('pendingMerchantKeys'))->toBe([]);
});

it('gives up on pending lookups after the timeout', function () {
    seedVisibleMerchants($this->row);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->call('updateVisibleMerchants');

    $this->travel(4)->minutes();

    $component->call('pollMerchantBrands')
        ->assertDontSeeHtml('wire:poll.4s="pollMerchantBrands"');

    expect($component->get('pendingMerchantKeys'))->toBe([])
        ->and($component->get('pendingSince'))->toBeNull();
});

it('adds a single identify request to the pending list', function () {
    $row = ($this->row)('VISA WOOLWORTHS SYDNEY');

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->call('identifyMerchant', $row->id)
        ->assertSet('pendingMerchantKeys', [$row->merchant_key => now()->getTimestamp()])
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => $params['slots']['text'] === 'Looking up the merchant…');
});
