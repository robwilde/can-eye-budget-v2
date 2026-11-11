<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Jobs\ResolveMerchantBrandJob;
use App\Livewire\MerchantManager;
use App\Models\AuditEvent;
use App\Models\Merchant;
use App\Models\MerchantAlias;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Merchants\MerchantMerger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('requires a signed-in user to view the merchants page', function () {
    $this->get(route('merchants'))->assertRedirect(route('login'));
    $this->actingAs($this->user)->get(route('merchants'))->assertOk()->assertSee('Merchants');
});

it('lists recently unlinked merchants for thirty days', function () {
    $this->travelTo('2026-11-01 09:00:00');
    $merchant = Merchant::factory()->for($this->user)->create(['name' => 'Corner Cafe']);
    Transaction::factory()->count(8)->for($this->user)->create(['merchant_id' => $merchant->id]);
    app(MerchantMerger::class)->unlink($this->user, $merchant);

    Livewire::actingAs($this->user)->test(MerchantManager::class)->assertSeeInOrder(['Recently unlinked', 'Corner Cafe']);

    $this->travelTo('2026-11-30 09:00:00');
    Livewire::actingAs($this->user)->test(MerchantManager::class)->assertSee('Corner Cafe');
    expect(Livewire::actingAs($this->user)->test(MerchantManager::class)->html())->toContain('data-testid="recently-unlinked-row"');

    $this->travelTo('2026-12-02 09:00:00');
    expect(Livewire::actingAs($this->user)->test(MerchantManager::class)->html())->not->toContain('data-testid="recently-unlinked-row"');
});

it('leaves merchants still in use out of recently unlinked', function () {
    $merchant = Merchant::factory()->for($this->user)->create(['name' => 'Corner Cafe']);
    Transaction::factory()->count(3)->for($this->user)->create(['merchant_id' => $merchant->id]);
    app(MerchantMerger::class)->unlink($this->user, $merchant);
    Transaction::factory()->for($this->user)->create(['merchant_id' => $merchant->id]);

    expect(Livewire::actingAs($this->user)->test(MerchantManager::class)->html())->not->toContain('data-testid="recently-unlinked-row"');
});

it('records an audit event when merchants are merged', function () {
    [$a, $b, $target] = Merchant::factory()->count(3)->for($this->user)->create();
    Transaction::factory()->count(2)->for($this->user)->create(['merchant_id' => $a->id]);

    Livewire::actingAs($this->user)
        ->test(MerchantManager::class)
        ->set('mergeSources', [(string) $a->id, (string) $b->id])
        ->set('mergeTarget', (string) $target->id)
        ->call('merge')
        ->assertHasNoErrors();

    expect(AuditEvent::query()->where('action', 'livewire.merchant-manager.merge')->where('user_id', $this->user->id)->exists())->toBeTrue()
        ->and(Merchant::query()->where('user_id', $this->user->id)->pluck('id')->all())->toBe([$target->id])
        ->and(Transaction::query()->where('merchant_id', $target->id)->count())->toBe(2)
        ->and(MerchantAlias::query()->where('merchant_id', $target->id)->count())->toBe(2);
});

it('refuses to merge into or from another users merchant', function () {
    $own = Merchant::factory()->for($this->user)->create();
    $foreign = Merchant::factory()->for(User::factory()->create())->create();

    Livewire::actingAs($this->user)
        ->test(MerchantManager::class)
        ->set('mergeSources', [(string) $own->id])
        ->set('mergeTarget', (string) $foreign->id)
        ->call('merge')
        ->assertHasErrors('mergeTarget');

    expect(Merchant::query()->whereKey([$own->id, $foreign->id])->count())->toBe(2);
});

it('creates edits searches and deletes a merchant', function () {
    Queue::fake();

    Livewire::actingAs($this->user)
        ->test(MerchantManager::class)
        ->set('name', 'Woolworths')
        ->set('colour', 'nope')
        ->set('website', 'woolworths.com.au')
        ->call('save')
        ->assertHasNoErrors()
        ->set('name', 'Woolworths')
        ->call('save')
        ->assertHasErrors('name');

    $merchant = Merchant::query()->where('user_id', $this->user->id)->sole();

    expect(Merchant::PALETTE)->toContain($merchant->colour);

    Livewire::actingAs($this->user)
        ->test(MerchantManager::class)
        ->call('edit', $merchant->id)
        ->set('name', 'Woolies')
        ->call('save')
        ->assertHasNoErrors()
        ->set('search', 'zzz')
        ->assertDontSee('Woolies')
        ->set('search', 'wool')
        ->assertSee('Woolies')
        ->call('delete', $merchant->id);

    expect(Merchant::query()->count())->toBe(0);
});

it('cannot edit or delete another users merchant', function () {
    $foreign = Merchant::factory()->for(User::factory()->create())->create();

    $component = Livewire::actingAs($this->user)->test(MerchantManager::class);

    expect(fn () => $component->call('edit', $foreign->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $component->call('delete', $foreign->id))->toThrow(ModelNotFoundException::class);

    expect(Merchant::query()->whereKey($foreign->id)->exists())->toBeTrue();
});

it('queues a brand lookup for a saved merchant only when enrichment is on', function () {
    Queue::fake();
    config()->set('services.context_dev.enrichment_enabled', false);
    Livewire::actingAs($this->user)->test(MerchantManager::class)->set('name', 'Off Cafe')->call('save');
    Queue::assertNothingPushed();

    config()->set('services.context_dev.enrichment_enabled', true);
    Livewire::actingAs($this->user)->test(MerchantManager::class)->set('name', 'On Cafe')->call('save');
    Queue::assertPushed(ResolveMerchantBrandJob::class, fn (ResolveMerchantBrandJob $job): bool => $job->merchantKey === 'ON CAFE');
});
