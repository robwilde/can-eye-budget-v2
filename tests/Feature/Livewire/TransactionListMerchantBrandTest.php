<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\MerchantBrandStatus;
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
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));
    config(['services.context_dev.enrichment_enabled' => true]);

    $this->user = User::factory()->create();
    $this->row = Transaction::factory()->for($this->user)->create([
        'description' => 'VISA WOOLWORTHS 1234 SYDNEY',
        'direction' => TransactionDirection::Debit,
        'merchant_name' => null,
        'post_date' => now()->subDays(2),
    ]);
});

it('shows the resolved brand beside the original descriptor', function () {
    MerchantBrand::factory()->for($this->user)->create([
        'merchant_key' => $this->row->merchant_key,
        'title' => 'Woolworths',
        'logo_url' => 'https://media.brand.dev/woolworths.png',
    ]);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->assertSeeHtml('https://media.brand.dev/woolworths.png')
        ->assertSee('Woolworths')
        ->assertSee('VISA WOOLWORTHS 1234 SYDNEY')
        ->assertSeeHtml('data-testid="veto-merchant-'.$this->row->id.'"')
        ->assertDontSeeHtml('data-testid="identify-merchant-'.$this->row->id.'"');
});

it('does not show another user\'s brand for the same merchant key', function () {
    MerchantBrand::factory()->for(User::factory()->create())->create([
        'merchant_key' => $this->row->merchant_key,
        'logo_url' => 'https://media.brand.dev/other.png',
    ]);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->assertDontSeeHtml('https://media.brand.dev/other.png');
});

it('vetoes the merchant, hiding the brand and blocking future lookups', function () {
    MerchantBrand::factory()->for($this->user)->create(['merchant_key' => $this->row->merchant_key]);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->call('vetoMerchantBrand', $this->row->id)
        ->assertDontSeeHtml('https://media.brand.dev/woolworths.png')
        ->assertDontSeeHtml('data-testid="identify-merchant-'.$this->row->id.'"');

    $brand = MerchantBrand::query()->sole();

    expect($brand->status)->toBe(MerchantBrandStatus::Vetoed)
        ->and($brand->blocksLookup())->toBeTrue()
        ->and($this->row->fresh()->merchant_key)->toBe($this->row->merchant_key);
});

it('offers and queues a user-requested lookup for an unenriched merchant', function () {
    Queue::fake([ResolveMerchantBrandJob::class]);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->assertSeeHtml('data-testid="identify-merchant-'.$this->row->id.'"')
        ->call('identifyMerchant', $this->row->id);

    Queue::assertPushed(ResolveMerchantBrandJob::class, fn (ResolveMerchantBrandJob $job): bool => $job->merchantKey === $this->row->merchant_key
        && $job->user->is($this->user));
});

it('offers no lookup when enrichment is off or the row fails the gate', function (Closure $arrange) {
    Queue::fake([ResolveMerchantBrandJob::class]);
    $arrange($this->row);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->assertDontSeeHtml('data-testid="identify-merchant-'.$this->row->id.'"')
        ->call('identifyMerchant', $this->row->id);

    Queue::assertNothingPushed();
})->with([
    'enrichment off' => [fn () => config(['services.context_dev.enrichment_enabled' => false])],
    'transfer wording' => [fn (Transaction $row) => $row->update(['description' => 'TRANSFER TO J SMITH'])],
    'daily cap spent' => [fn () => config(['services.context_dev.daily_credit_cap' => 0])],
]);

it('offers no lookup for a row whose merchant_key was never persisted', function () {
    Queue::fake([ResolveMerchantBrandJob::class]);
    // The job selects rows by the persisted column, so a derived key could never resolve.
    Illuminate\Support\Facades\DB::table('transactions')->where('id', $this->row->id)->update(['merchant_key' => null]);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->assertDontSeeHtml('data-testid="identify-merchant-'.$this->row->id.'"')
        ->call('identifyMerchant', $this->row->id);

    Queue::assertNothingPushed();
});

it('refuses to act on another user\'s transaction', function () {
    $foreign = Transaction::factory()->create();

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->call('vetoMerchantBrand', $foreign->id);
})->throws(Illuminate\Database\Eloquent\ModelNotFoundException::class);

it('shows the brand for a row whose merchant_key was never persisted', function () {
    // Pre-backfill rows: the saving hook cannot be bypassed through Eloquent.
    Illuminate\Support\Facades\DB::table('transactions')->where('id', $this->row->id)->update(['merchant_key' => null]);
    MerchantBrand::factory()->for($this->user)->create([
        'merchant_key' => $this->row->fresh()->resolveMerchantKey(),
        'logo_url' => 'https://media.brand.dev/woolworths.png',
    ]);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->assertSeeHtml('https://media.brand.dev/woolworths.png')
        ->assertSeeHtml('data-testid="veto-merchant-'.$this->row->id.'"');
});
