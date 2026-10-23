<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountClass;
use App\Enums\AccountGroup;
use App\Enums\AuditOutcome;
use App\Enums\ImportSource;
use App\Enums\TransactionDirection;
use App\Enums\ValuationKind;
use App\Livewire\AccountManager;
use App\Models\Account;
use App\Models\AccountValuation;
use App\Models\AuditEvent;
use App\Models\RedbarkAccount;
use App\Models\RedbarkFeed;
use App\Models\StatementReconciliation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountValuationService;
use Carbon\CarbonImmutable;
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
        ->currency->toBe('AUD')
        ->balance_source->toBe(ImportSource::Manual);
    expect($account->balance_updated_at)->not->toBeNull();
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

test('cib: add account modal labels render as cib-label spans', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->call('openAddModal');

    $html = $component->html();

    expect(mb_substr_count($html, 'class="cib-label'))->toBeGreaterThanOrEqual(5);

    $component->assertSeeHtml('wire:model="name"');
});

function redbarkLinkedAccount(User $user, string $name): Account
{
    $account = Account::factory()->for($user)->create(['name' => $name]);
    RedbarkAccount::factory()->create([
        'redbark_feed_id' => RedbarkFeed::factory()->create(['user_id' => $user->id])->id,
        'account_id' => $account->id,
    ]);

    return $account;
}

test('redbark accounts link to last month\'s reconciliation, other accounts do not', function () {
    $user = User::factory()->create();
    $linked = redbarkLinkedAccount($user, 'Everyday');
    $plain = Account::factory()->for($user)->create(['name' => 'Cash']);

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->assertSeeHtml('data-test="account-reconcile-link-'.$linked->id.'"')
        ->assertSeeHtml(route('accounts.reconcile', $linked))
        ->assertDontSeeHtml('data-test="account-reconcile-link-'.$plain->id.'"');
});

test('a closed reconciliation for last month shows the reconciled pill instead of the link', function (string $state, bool $closed) {
    $this->travelTo('2026-09-27 10:00:00');
    $user = User::factory()->create();
    $account = redbarkLinkedAccount($user, 'Everyday');

    StatementReconciliation::factory()->{$state}()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
    ]);

    $page = Livewire::actingAs($user)->test(AccountManager::class);

    if ($closed) {
        $page->assertSeeHtml('data-test="account-reconciled-'.$account->id.'"')
            ->assertDontSeeHtml('data-test="account-reconcile-link-'.$account->id.'"');
    } else {
        $page->assertSeeHtml('data-test="account-reconcile-link-'.$account->id.'"')
            ->assertDontSeeHtml('data-test="account-reconciled-'.$account->id.'"');
    }
})->with([
    'closed' => ['closed', true],
    'open' => ['open', false],
]);

test('a closed reconciliation for an older month does not count as last month', function () {
    $this->travelTo('2026-09-27 10:00:00');
    $user = User::factory()->create();
    $account = redbarkLinkedAccount($user, 'Everyday');

    StatementReconciliation::factory()->closed()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
    ]);

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->assertSeeHtml('data-test="account-reconcile-link-'.$account->id.'"');
});

test('cannot reconcile another users account', function () {
    $user = User::factory()->create();
    $other = Account::factory()->for(User::factory()->create())->savings()->create(['balance_source' => ImportSource::Manual, 'balance' => 5000]);

    Livewire::actingAs($user)->test(AccountManager::class)
        ->set('reconcilingAccountId', $other->id)
        ->set('reconcileBalance', '99.00')
        ->set('reconcileDate', '2026-07-01')
        ->call('reconcile')
        ->assertSet('reconcilePreview', null)
        ->call('confirmReconcile');

    expect(AccountValuation::query()->count())->toBe(0)
        ->and($other->fresh()->balance)->toBe(5000);
});

test('records an audit event when an account is reconciled', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->savings()->create(['balance_source' => ImportSource::Manual]);

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openReconcileModal', $account->id)
        ->set('reconcileBalance', '120.00')
        ->set('reconcileDate', '2026-07-01')
        ->call('reconcile')
        ->call('confirmReconcile');

    $event = AuditEvent::query()->where('action', 'livewire.account-manager.confirmReconcile')->sole();
    expect($event->user_id)->toBe($user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success);
});

test('reviewing a reconciliation writes nothing until it is confirmed', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->savings()->create(['balance_source' => ImportSource::Manual, 'balance' => 10000]);

    $component = Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openReconcileModal', $account->id)
        ->set('reconcileBalance', '150.00')
        ->set('reconcileDate', '2026-07-01')
        ->call('reconcile')
        ->assertSet('reconcilePreview', ['before' => 10000, 'after' => 15000, 'date' => '2026-07-01']);

    expect(AccountValuation::query()->count())->toBe(0)
        ->and($account->fresh()->balance)->toBe(10000);

    $component->call('confirmReconcile')->assertSet('showReconcileModal', false);

    expect($account->valuations()->sole()->balance)->toBe(15000);
});

test('shows the service error instead of saving an invalid reconciliation date', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->savings()->create(['balance_source' => ImportSource::Manual]);
    app(AccountValuationService::class)->setOpeningBalance($account, 1000, CarbonImmutable::parse('2026-05-01'));

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openReconcileModal', $account->id)
        ->set('reconcileBalance', '5.00')
        ->set('reconcileDate', '2026-04-01')
        ->call('reconcile')
        ->call('confirmReconcile')
        ->assertHasErrors('reconcileDate')
        ->assertSet('reconcilePreview', null);

    expect($account->valuations()->count())->toBe(1);
});

test('edits and deletes a reconciliation from the history list', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->savings()->create(['balance_source' => ImportSource::Manual]);
    $valuation = app(AccountValuationService::class)->reconcile($account, 10000, CarbonImmutable::parse('2026-06-01'));

    $component = Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openReconcileModal', $account->id)
        ->assertSee('Earlier reconciliations')
        ->call('editReconciliation', $valuation->id)
        ->assertSet('reconcileBalance', '100.00')
        ->set('reconcileBalance', '110.00')
        ->call('reconcile')
        ->call('confirmReconcile');

    expect($account->valuations()->sole()->balance)->toBe(11000);

    $component->call('openReconcileModal', $account->id)->call('deleteReconciliation', $valuation->id);

    expect($account->valuations()->count())->toBe(0)
        ->and($account->fresh()->reconciled_on)->toBeNull();
});

test('saves an opening balance from the account form', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openAddModal')
        ->set('name', 'Everyday')
        ->set('balance', '0')
        ->set('openingBalance', '1000.00')
        ->set('openingBalanceDate', '2026-01-01')
        ->call('save')
        ->assertHasNoErrors();

    $account = Account::query()->where('user_id', $user->id)->sole();
    expect($account->valuations()->sole()->balance)->toBe(100000);
});

test('rolls back the account rename when the opening balance date is not before the oldest entry', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance_source' => ImportSource::Manual]);
    Transaction::factory()->for($user)->for($account)->create(['post_date' => '2026-03-01']);

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openEditModal', $account->id)
        ->set('openingBalance', '50.00')
        ->set('openingBalanceDate', '2026-03-01')
        ->set('name', 'Renamed')
        ->call('save')
        ->assertHasErrors('openingBalanceDate');

    expect($account->valuations()->count())->toBe(0)
        ->and($account->fresh()->name)->not->toBe('Renamed');
});

test('rolls back the new account when the opening balance date is too far back', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openAddModal')
        ->set('name', 'Ancient')
        ->set('balance', '0')
        ->set('openingBalance', '10.00')
        ->set('openingBalanceDate', '1990-01-01')
        ->call('save')
        ->assertHasErrors('openingBalanceDate');

    expect(Account::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('reopening the reconcile modal drops a dismissed edit so a new reconciliation is added', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->savings()->create(['balance_source' => ImportSource::Manual]);
    $valuation = app(AccountValuationService::class)->reconcile($account, 10000, CarbonImmutable::parse('2026-06-01'));

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openReconcileModal', $account->id)
        ->call('editReconciliation', $valuation->id)
        ->set('showReconcileModal', false)
        ->call('openReconcileModal', $account->id)
        ->assertSet('editingValuationId', null)
        ->set('reconcileBalance', '200.00')
        ->call('reconcile')
        ->call('confirmReconcile');

    expect($account->valuations()->count())->toBe(2)
        ->and($valuation->fresh()->balance)->toBe(10000);
});

test('reopening the reconcile modal drops a stale preview', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->savings()->create(['balance_source' => ImportSource::Manual]);

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openReconcileModal', $account->id)
        ->call('reconcile')
        ->assertNotSet('reconcilePreview', null)
        ->set('showReconcileModal', false)
        ->call('openReconcileModal', $account->id)
        ->assertSet('reconcilePreview', null);
});

test('deleting another reconciliation keeps the one being edited', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->savings()->create(['balance_source' => ImportSource::Manual]);
    $service = app(AccountValuationService::class);
    $edited = $service->reconcile($account, 10000, CarbonImmutable::parse('2026-06-01'));
    $other = $service->reconcile($account, 20000, CarbonImmutable::parse('2026-07-01'));

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openReconcileModal', $account->id)
        ->call('editReconciliation', $edited->id)
        ->call('deleteReconciliation', $other->id)
        ->assertSet('editingValuationId', $edited->id);
});

test('a foreign reconciliation cannot be edited by pointing the modal at another users account', function () {
    $user = User::factory()->create();
    $mine = Account::factory()->for($user)->savings()->create(['balance_source' => ImportSource::Manual]);
    $theirs = Account::factory()->for(User::factory()->create())->savings()->create(['balance_source' => ImportSource::Manual]);
    $foreign = app(AccountValuationService::class)->reconcile($theirs, 7000, CarbonImmutable::parse('2026-06-01'));

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openReconcileModal', $mine->id)
        ->set('reconcilingAccountId', $theirs->id)
        ->call('editReconciliation', $foreign->id)
        ->assertSet('editingValuationId', null)
        ->call('deleteReconciliation', $foreign->id);

    expect($theirs->valuations()->count())->toBe(1);
});

it('keeps an edited current balance on a manual account with an opening balance', function () {
    $this->travelTo('2026-06-15 10:00:00');
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance_source' => ImportSource::Manual]);
    Transaction::factory()->for($user)->for($account)->create([
        'amount' => 20000,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-03-01',
    ]);
    app(AccountValuationService::class)->setOpeningBalance($account, 100000, CarbonImmutable::parse('2026-01-01'));

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openEditModal', $account->id)
        ->assertSet('balance', '800.00')
        ->set('balance', '1500.00')
        ->call('save')
        ->assertHasNoErrors();

    $opening = $account->valuations()->sole();
    expect($account->fresh()->balance)->toBe(150000)
        ->and($opening->kind)->toBe(ValuationKind::OpeningBalance)
        ->and($opening->balance)->toBe(170000)
        ->and($opening->valued_on->toDateString())->toBe('2026-01-01');
});

it('records an edited current balance as a reconciliation on a non-cash account', function () {
    $this->travelTo('2026-06-15 10:00:00');
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->savings()->create(['balance_source' => ImportSource::Manual]);
    app(AccountValuationService::class)->setOpeningBalance($account, 100000, CarbonImmutable::parse('2026-01-01'));

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openEditModal', $account->id)
        ->set('balance', '1500.00')
        ->call('save')
        ->assertHasNoErrors();

    $opening = $account->valuations()->where('kind', ValuationKind::OpeningBalance)->sole();
    $reconciliation = $account->valuations()->where('kind', ValuationKind::Reconciliation)->sole();
    expect($account->fresh()->balance)->toBe(150000)
        ->and($opening->balance)->toBe(100000)
        ->and($opening->valued_on->toDateString())->toBe('2026-01-01')
        ->and($reconciliation->valued_on->toDateString())->toBe('2026-06-15')
        ->and($reconciliation->balance)->toBe(150000)
        ->and($reconciliation->difference)->toBe(50000);
});

it('leaves the balance alone when only account details change', function () {
    $this->travelTo('2026-06-15 10:00:00');
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance_source' => ImportSource::Manual]);
    Transaction::factory()->for($user)->for($account)->create([
        'amount' => 20000,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-03-01',
    ]);
    app(AccountValuationService::class)->setOpeningBalance($account, 100000, CarbonImmutable::parse('2026-01-01'));
    $before = $account->fresh();
    $valuationsBefore = $account->valuations()->get()->map->only(['id', 'kind', 'valued_on', 'balance', 'difference', 'updated_at']);
    $this->travelTo('2026-06-20 10:00:00');

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openEditModal', $account->id)
        ->set('name', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    $after = $account->fresh();
    expect($after->name)->toBe('Renamed')
        ->and($after->balance)->toBe(80000)
        ->and($after->balance_updated_at)->toEqual($before->balance_updated_at)
        ->and($account->valuations()->get()->map->only(['id', 'kind', 'valued_on', 'balance', 'difference', 'updated_at']))->toEqual($valuationsBefore);
});

it('ends on the entered current balance when the opening balance is edited in the same save', function () {
    $this->travelTo('2026-06-15 10:00:00');
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance_source' => ImportSource::Manual]);
    Transaction::factory()->for($user)->for($account)->create([
        'amount' => 20000,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-03-01',
    ]);
    app(AccountValuationService::class)->setOpeningBalance($account, 100000, CarbonImmutable::parse('2026-01-01'));

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openEditModal', $account->id)
        ->set('openingBalance', '2000.00')
        ->set('balance', '1500.00')
        ->call('save')
        ->assertHasNoErrors();

    expect($account->fresh()->balance)->toBe(150000)
        ->and($account->valuations()->sole()->balance)->toBe(170000);
});

it('keeps a reconciliation made after the form opened when only account details change', function () {
    $this->travelTo('2026-06-15 10:00:00');
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->savings()->create(['balance_source' => ImportSource::Manual]);
    $service = app(AccountValuationService::class);
    $service->setOpeningBalance($account, 100000, CarbonImmutable::parse('2026-01-01'));

    $component = Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openEditModal', $account->id);

    $service->reconcile($account, 150000, CarbonImmutable::parse('2026-06-15'));

    $component->set('name', 'Renamed')->call('save')->assertHasNoErrors();

    $reconciliation = $account->valuations()->where('kind', ValuationKind::Reconciliation)->sole();
    expect($account->fresh()->balance)->toBe(150000)
        ->and($reconciliation->balance)->toBe(150000)
        ->and($account->valuations()->count())->toBe(2);
});

it('keeps a current balance set after the form opened when only account details change', function () {
    $this->travelTo('2026-06-15 10:00:00');
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance_source' => ImportSource::Manual]);
    Transaction::factory()->for($user)->for($account)->create([
        'amount' => 20000,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-03-01',
    ]);
    app(AccountValuationService::class)->setOpeningBalance($account, 100000, CarbonImmutable::parse('2026-01-01'));

    $component = Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openEditModal', $account->id);

    app(AccountValuationService::class)->setCurrentBalance($account, 150000);

    $component->set('name', 'Renamed')->call('save')->assertHasNoErrors();

    $opening = $account->valuations()->sole();
    expect($account->fresh()->balance)->toBe(150000)
        ->and($opening->kind)->toBe(ValuationKind::OpeningBalance)
        ->and($opening->balance)->toBe(170000)
        ->and($opening->valued_on->toDateString())->toBe('2026-01-01');
});

function providerFedAccount(User $user, ImportSource $source): Account
{
    return Account::factory()->for($user)->create([
        'name' => 'Everyday',
        'balance' => 123456,
        'balance_source' => $source,
        'balance_updated_at' => CarbonImmutable::parse('2026-06-15 09:00:00'),
    ]);
}

function renameKeepsProviderBalance(ImportSource $source): void
{
    test()->travelTo('2026-06-20 10:00:00');
    $user = User::factory()->create();
    $account = providerFedAccount($user, $source);

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openEditModal', $account->id)
        ->set('name', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    $after = $account->fresh();
    expect($after->name)->toBe('Renamed')
        ->and($after->balance)->toBe(123456)
        ->and($after->balance_source)->toBe($source)
        ->and($after->balance_updated_at)->toEqual(CarbonImmutable::parse('2026-06-15 09:00:00'));
}

it('keeps balance and source when renaming a redbark account', function () {
    renameKeepsProviderBalance(ImportSource::Redbark);
});

it('keeps balance and source when renaming a csv account', function () {
    renameKeepsProviderBalance(ImportSource::Csv);
});

it('does not overwrite a provider balance updated after the edit form opened', function () {
    $this->travelTo('2026-06-20 10:00:00');
    $user = User::factory()->create();
    $account = providerFedAccount($user, ImportSource::Redbark);

    $component = Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openEditModal', $account->id);

    $account->update(['balance' => 200000, 'balance_updated_at' => CarbonImmutable::parse('2026-06-20 09:30:00')]);

    $component->set('name', 'Renamed')->call('save')->assertHasNoErrors();

    $after = $account->fresh();
    expect($after->balance)->toBe(200000)
        ->and($after->balance_source)->toBe(ImportSource::Redbark)
        ->and($after->balance_updated_at)->toEqual(CarbonImmutable::parse('2026-06-20 09:30:00'));
});

it('still saves non-balance details on provider-fed accounts', function () {
    $user = User::factory()->create();
    $account = providerFedAccount($user, ImportSource::Csv);

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openEditModal', $account->id)
        ->set('name', 'Platinum Card')
        ->set('type', AccountClass::CreditCard->value)
        ->set('group', AccountGroup::LongTermSavings->value)
        ->set('institution', 'Westpac')
        ->set('description', 'Bills card')
        ->set('hasCreditLimit', true)
        ->set('credit_limit', '5000.00')
        ->call('save')
        ->assertHasNoErrors();

    $after = $account->fresh();
    expect($after->name)->toBe('Platinum Card')
        ->and($after->type)->toBe(AccountClass::CreditCard)
        ->and($after->group)->toBe(AccountGroup::LongTermSavings)
        ->and($after->institution)->toBe('Westpac')
        ->and($after->description)->toBe('Bills card')
        ->and($after->credit_limit)->toBe(500000)
        ->and($after->balance_source)->toBe(ImportSource::Csv);
});

it('shows the current balance read-only only when editing a provider-fed account', function (ImportSource $source, bool $readOnly) {
    $user = User::factory()->create();
    $account = providerFedAccount($user, $source);

    $component = Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openEditModal', $account->id);

    preg_match('/<input[^>]*id="account-balance"[^>]*>/', $component->html(), $input);
    expect(str_contains($input[0], 'readonly'))->toBe($readOnly);

    $readOnly
        ? $component->assertSeeHtml('data-test="account-balance-provider-note"')
        : $component->assertDontSeeHtml('data-test="account-balance-provider-note"');
})->with([
    'redbark' => [ImportSource::Redbark, true],
    'csv' => [ImportSource::Csv, true],
    'manual' => [ImportSource::Manual, false],
]);

it('ignores a balance sent for a provider-fed account', function (string $balance) {
    $this->travelTo('2026-06-20 10:00:00');
    $user = User::factory()->create();
    $account = providerFedAccount($user, ImportSource::Redbark);

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openEditModal', $account->id)
        ->set('balance', $balance)
        ->call('save')
        ->assertHasNoErrors();

    $after = $account->fresh();
    expect($after->balance)->toBe(123456)
        ->and($after->balance_source)->toBe(ImportSource::Redbark)
        ->and($after->balance_updated_at)->toEqual(CarbonImmutable::parse('2026-06-15 09:00:00'))
        ->and($after->valuations()->exists())->toBeFalse();
})->with([
    'changed' => ['999.99'],
    'blank' => [''],
]);

it('lets a new account set its balance after editing a provider-fed one', function () {
    $user = User::factory()->create();
    $account = providerFedAccount($user, ImportSource::Redbark);

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openEditModal', $account->id)
        ->assertSet('balanceReadOnly', true)
        ->call('openAddModal')
        ->assertSet('balanceReadOnly', false);
});
