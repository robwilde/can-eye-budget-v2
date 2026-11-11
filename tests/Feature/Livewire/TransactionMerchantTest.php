<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Livewire\TransactionList;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\Merchant;
use App\Models\MerchantBrand;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Merchants\MerchantMerger;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo('2026-06-15');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function merchantRow(User $user, Account $account, string $description, array $overrides = []): Transaction
{
    return Transaction::factory()->for($user)->for($account)->manual()->create(array_merge([
        'post_date' => '2026-06-10',
        'amount' => 1000,
        'direction' => TransactionDirection::Debit,
        'description' => $description,
        'transfer_pair_id' => null,
    ], $overrides));
}

it('filters transactions with no merchant', function () {
    $merchant = Merchant::factory()->for($this->user)->create();
    merchantRow($this->user, $this->account, 'ALPHA ROW', ['merchant_id' => $merchant->id]);
    merchantRow($this->user, $this->account, 'BETA ROW');

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('merchant', 'none')
        ->assertSee('BETA ROW')
        ->assertDontSee('ALPHA ROW')
        ->set('merchant', (string) $merchant->id)
        ->assertSee('ALPHA ROW')
        ->assertDontSee('BETA ROW')
        ->set('merchant', 'rubbish')
        ->assertSet('merchant', null)
        ->assertSee('ALPHA ROW')
        ->assertSee('BETA ROW');
});

it('cannot assign another users merchant', function () {
    $foreign = Merchant::factory()->for(User::factory()->create())->create();
    $row = merchantRow($this->user, $this->account, 'PICKER ROW');

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)
        ->set('merchantId', $foreign->id)
        ->call('save')
        ->assertHasErrors('merchantId');

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$row->id => true])
        ->set('bulkMerchantId', (string) $foreign->id)
        ->call('applyMerchantToSelection')
        ->assertSet('bulkError', 'That merchant is not available.');

    expect(Transaction::query()->where('user_id', $this->user->id)->whereNotNull('merchant_id')->exists())->toBeFalse()
        ->and(Transaction::query()->where('parent_transaction_id', $row->id)->exists())->toBeFalse();
});

it('sets a merchant on the selection and locks it against sync', function () {
    $merchant = Merchant::factory()->for($this->user)->create();
    $rows = collect(['ONE', 'TWO', 'THREE'])->map(fn (string $name): Transaction => merchantRow($this->user, $this->account, $name));
    $other = merchantRow($this->user, $this->account, 'NOT SELECTED');
    $foreignRow = merchantRow(User::factory()->create(), Account::factory()->create(), 'FOREIGN');

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', $rows->mapWithKeys(fn (Transaction $row): array => [$row->id => true])->all() + [$foreignRow->id => true])
        ->set('bulkMerchantId', (string) $merchant->id)
        ->call('applyMerchantToSelection')
        ->assertSet('bulkError', null);

    expect(Transaction::query()->where('merchant_id', $merchant->id)->count())->toBe(3)
        ->and($rows->first()->fresh()->locked_fields)->toContain('merchant')
        ->and($other->fresh()->merchant_id)->toBeNull()
        ->and($foreignRow->fresh()->merchant_id)->toBeNull();
});

it('picks and creates a merchant in the transaction modal', function () {
    $merchant = Merchant::factory()->for($this->user)->create(['name' => 'Corner Cafe']);
    $row = merchantRow($this->user, $this->account, 'MODAL ROW');

    $component = Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)
        ->set('merchantId', $merchant->id)
        ->call('save')
        ->assertHasNoErrors();

    $edited = Transaction::query()->where('parent_transaction_id', $row->id)->sole();

    expect($edited->merchant_id)->toBe($merchant->id)
        ->and($edited->locked_fields)->toContain('merchant');

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->set('newMerchantName', 'corner cafe')
        ->call('createMerchant')
        ->assertSet('merchantId', $merchant->id)
        ->set('newMerchantName', 'Brand New')
        ->call('createMerchant')
        ->assertSet('newMerchantName', '');

    expect(Merchant::query()->where('user_id', $this->user->id)->count())->toBe(2)
        ->and($component)->not->toBeNull();
});

it('adds a merchant to a new manual transaction', function () {
    $merchant = Merchant::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '12.50 coffee')
        ->set('accountId', $this->account->id)
        ->set('date', '2026-06-10')
        ->set('merchantId', $merchant->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::query()->where('description', 'coffee')->value('merchant_id'))->toBe($merchant->id);
});

it('shows the target merchant brand on a merged transaction', function () {
    $source = Merchant::factory()->for($this->user)->create(['name' => 'Source Shop', 'merchant_key' => 'SOURCE SHOP']);
    $target = Merchant::factory()->for($this->user)->create(['name' => 'Target Shop', 'merchant_key' => 'TARGET SHOP']);
    merchantRow($this->user, $this->account, 'MERGED ROW', ['merchant_name' => 'Source Shop', 'merchant_id' => $source->id]);
    MerchantBrand::factory()->for($this->user)->create(['merchant_key' => 'TARGET SHOP', 'title' => 'Target Brand', 'logo_url' => 'https://media.brand.dev/target.png']);

    app(MerchantMerger::class)->merge($this->user, [$source], $target);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->assertSeeHtml('https://media.brand.dev/target.png')
        ->assertSee('Target Brand');
});
