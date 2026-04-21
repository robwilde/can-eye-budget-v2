<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountClass;
use App\Enums\AccountGroup;
use App\Livewire\AccountManager;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

test('component renders for authenticated user', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->assertSuccessful();
});

test('shows empty state when no accounts exist', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->assertSee('No accounts yet');
});

test('lists only current user accounts', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Account::factory()->for($user)->create(['name' => 'My Account']);
    Account::factory()->for($otherUser)->create(['name' => 'Other Account']);

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->assertSee('My Account')
        ->assertDontSee('Other Account');
});

test('displays accounts grouped by account group', function () {
    $user = User::factory()->create();

    Account::factory()->for($user)->create(['name' => 'Everyday', 'group' => AccountGroup::DayToDay]);
    Account::factory()->for($user)->longTermSavings()->create(['name' => 'Savings Goal']);
    Account::factory()->for($user)->hidden()->create(['name' => 'Hidden Fund']);

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->assertSee('Day to Day')
        ->assertSee('Long Term Savings')
        ->assertSee('Hidden')
        ->assertSee('Everyday')
        ->assertSee('Savings Goal')
        ->assertSee('Hidden Fund');
});

test('can add a new account with valid data', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->call('openAddModal')
        ->set('name', 'Test Account')
        ->set('balance', '1500.50')
        ->set('type', AccountClass::Transaction->value)
        ->set('group', AccountGroup::DayToDay->value)
        ->call('save')
        ->assertSet('showFormModal', false);

    $account = Account::query()->where('user_id', $user->id)->first();
    expect($account)
        ->name->toBe('Test Account')
        ->balance->toBe(150050)
        ->type->toBe(AccountClass::Transaction)
        ->group->toBe(AccountGroup::DayToDay)
        ->currency->toBe('AUD');
});

test('validates required fields on add', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->call('openAddModal')
        ->set('name', '')
        ->set('balance', '')
        ->set('type', '')
        ->set('group', '')
        ->call('save')
        ->assertHasErrors(['name', 'balance', 'type', 'group']);
});

test('balance is stored as cents', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->call('openAddModal')
        ->set('name', 'Cents Test')
        ->set('balance', '42.99')
        ->set('type', AccountClass::Transaction->value)
        ->set('group', AccountGroup::DayToDay->value)
        ->call('save');

    expect(Account::query()->where('name', 'Cents Test')->first()->balance)->toBe(4299);
});

test('credit limit is stored as cents when enabled', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->call('openAddModal')
        ->set('name', 'Credit Test')
        ->set('balance', '-500.00')
        ->set('hasCreditLimit', true)
        ->set('credit_limit', '5000.00')
        ->set('type', AccountClass::CreditCard->value)
        ->set('group', AccountGroup::DayToDay->value)
        ->call('save');

    $account = Account::query()->where('name', 'Credit Test')->first();
    expect($account)
        ->credit_limit->toBe(500000)
        ->balance->toBe(-50000);
});

test('credit limit is null when checkbox unchecked', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->call('openAddModal')
        ->set('name', 'No Credit')
        ->set('balance', '1000.00')
        ->set('hasCreditLimit', false)
        ->set('type', AccountClass::Transaction->value)
        ->set('group', AccountGroup::DayToDay->value)
        ->call('save');

    expect(Account::query()->where('name', 'No Credit')->first()->credit_limit)->toBeNull();
});

test('can edit an existing account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create([
        'name' => 'Original Name',
        'balance' => 100000,
    ]);

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->call('openEditModal', $account->id)
        ->assertSet('name', 'Original Name')
        ->assertSet('editingAccountId', $account->id)
        ->set('name', 'Updated Name')
        ->call('save');

    expect($account->fresh()->name)->toBe('Updated Name');
});

test('can update account description', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['description' => null]);

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->call('openEditModal', $account->id)
        ->set('description', 'My savings for a rainy day')
        ->call('save');

    expect($account->fresh()->description)->toBe('My savings for a rainy day');
});

test('can delete an account without transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->call('confirmDelete', $account->id)
        ->assertSet('showDeleteModal', true)
        ->assertSet('deletingAccountName', $account->name)
        ->assertSet('deletingTransactionCount', 0)
        ->call('delete');

    expect(Account::query()->find($account->id))->toBeNull();
});

test('delete cascades and removes account with transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Transaction::factory()->for($user)->for($account)->count(3)->create();

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->call('confirmDelete', $account->id)
        ->assertSet('deletingTransactionCount', 3)
        ->call('delete');

    expect(Account::query()->find($account->id))
        ->toBeNull()
        ->and(Transaction::query()->where('account_id', $account->id)->count())->toBe(0);
});

test('cannot delete another user account', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherAccount = Account::factory()->for($otherUser)->create();

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->call('confirmDelete', $otherAccount->id)
        ->assertSet('showDeleteModal', false);

    expect(Account::query()->find($otherAccount->id))->not->toBeNull();
});

test('cannot edit another user account', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherAccount = Account::factory()->for($otherUser)->create();

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->call('openEditModal', $otherAccount->id)
        ->assertSet('editingAccountId', null)
        ->assertSet('showFormModal', false);
});

test('account group defaults to day to day on add', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->call('openAddModal')
        ->assertSet('group', AccountGroup::DayToDay->value);
});

test('account type defaults to transaction on add', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->call('openAddModal')
        ->assertSet('type', AccountClass::Transaction->value);
});

test('displays account type label and institution', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create([
        'name' => 'My Card',
        'type' => AccountClass::CreditCard,
        'institution' => 'Westpac',
    ]);

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->assertSee('My Card')
        ->assertSee('Credit Card')
        ->assertSee('Westpac');
});

test('shows formatted balance for each account', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create([
        'name' => 'Balance Test',
        'balance' => 123456,
    ]);

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->assertSee('$1,234.56');
});

test('institution is optional when adding account', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->call('openAddModal')
        ->set('name', 'No Institution')
        ->set('balance', '100.00')
        ->set('type', AccountClass::Transaction->value)
        ->set('group', AccountGroup::DayToDay->value)
        ->set('institution', '')
        ->call('save')
        ->assertHasNoErrors();

    expect(Account::query()->where('name', 'No Institution')->first()->institution)->toBeNull();
});

/* ------------------------------------------------------------------ */
/*  CIB visual contract (ticket #215) */
/* ------------------------------------------------------------------ */

test('cib: account group headers render via x-cib.sec-head + neutral x-cib.stat-pill', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->count(2)->create(['group' => AccountGroup::DayToDay]);
    Account::factory()->for($user)->longTermSavings()->create();

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->assertSeeHtml('class="sec-head')
        ->assertSeeHtml('stat-pill-neutral')
        ->assertSee('Day to Day')
        ->assertSee('Long Term Savings');
});

test('cib: account rows render via passive x-cib.tx-row with tone icon meta and amount', function () {
    $user = User::factory()->create();

    Account::factory()->for($user)->create([
        'name' => 'Everyday',
        'type' => AccountClass::Transaction,
        'balance' => 150050,
        'institution' => null,
        'credit_limit' => null,
    ]);
    Account::factory()->for($user)->create([
        'name' => 'Overdraft',
        'type' => AccountClass::Transaction,
        'balance' => -4200,
        'institution' => null,
        'credit_limit' => null,
    ]);
    Account::factory()->for($user)->create([
        'name' => 'Card',
        'type' => AccountClass::CreditCard,
        'balance' => -50000,
        'credit_limit' => 500000,
        'institution' => 'Westpac',
    ]);

    $component = Livewire::actingAs($user)->test(AccountManager::class);
    $html = $component->html();

    expect(mb_substr_count($html, 'class="tx-row passive'))->toBe(3);

    $component
        ->assertSeeHtml('class="tx-amt inc"')
        ->assertSeeHtml('class="tx-amt out"')
        ->assertSeeHtml('class="tx-meta"')
        ->assertSee('Limit $5,000.00');

    expect($html)->not->toContain('text-red-600 dark:text-red-500');
});

test('cib: empty state renders via x-cib.empty-state with yellow-on-black cta', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->assertSeeHtml('class="empty-state')
        ->assertSeeHtml('class="empty-state-action')
        ->assertSee('No accounts yet')
        ->assertSeeHtml('cib-yellow-pill');
});

test('cib: primary add account cta uses yellow-on-black pill not flux primary', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    $component = Livewire::actingAs($user)->test(AccountManager::class);
    $html = $component->html();

    $component->assertSeeHtml('cib-yellow-pill');

    expect($html)->not->toMatch('/variant="primary"[^>]*wire:click="openAddModal"/');
});

test('cib: manage categories trigger sits inside a cib-card', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->assertSeeHtml('class="cib-card')
        ->assertSee('Manage Categories');
});

test('cib: add account modal labels render as cib-label spans', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->call('openAddModal');

    $html = $component->html();

    expect(mb_substr_count($html, 'class="cib-label'))->toBeGreaterThanOrEqual(5);

    $component->assertSeeHtml('wire:model="name"');
});
