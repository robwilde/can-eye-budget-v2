<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Enums\AuditOutcome;
use App\Enums\ImportSource;
use App\Enums\RefreshTrigger;
use App\Enums\TransactionDirection;
use App\Jobs\SyncRedbarkFeedJob;
use App\Livewire\AccountManager;
use App\Livewire\ImportBank;
use App\Livewire\PlannedTransactionManager;
use App\Livewire\TransactionList;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\PlannedTransaction;
use App\Models\RedbarkAccount;
use App\Models\RedbarkFeed;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RedbarkTransactionMatcher;
use App\Services\TransactionIngestor;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-05-12');
    $this->user = User::factory()->create();
});

function lifecycleLinkedAccount(User $user, string $name = 'Everyday'): Account
{
    $account = Account::factory()->for($user)->create(['name' => $name, 'import_source' => ImportSource::Redbark, 'balance_source' => ImportSource::Redbark]);
    RedbarkAccount::factory()->create([
        'redbark_feed_id' => RedbarkFeed::factory()->create(['user_id' => $user->id])->id,
        'account_id' => $account->id,
    ]);

    return $account;
}

test('refuses to delete an account that is still linked to a bank feed', function () {
    $account = lifecycleLinkedAccount($this->user);
    Transaction::factory()->count(3)->for($this->user)->for($account)->create();

    Livewire::actingAs($this->user)
        ->test(AccountManager::class)
        ->call('confirmDelete', $account->id)
        ->call('delete')
        ->assertHasErrors(['delete'])
        ->assertSet('showDeleteModal', true);

    expect(Account::query()->whereKey($account->id)->exists())->toBeTrue()
        ->and(Transaction::query()->where('account_id', $account->id)->count())->toBe(3)
        ->and($account->redbarkAccount()->exists())->toBeTrue();
});

test('deletes an account once its bank feed is unlinked', function () {
    $account = lifecycleLinkedAccount($this->user);

    Livewire::actingAs($this->user)
        ->test(AccountManager::class)
        ->call('unlinkAccount', $account->id)
        ->call('confirmDelete', $account->id)
        ->call('delete')
        ->assertHasNoErrors();

    expect(Account::query()->whereKey($account->id)->exists())->toBeFalse();
});

test('hides a disabled account from account pickers', function () {
    $active = Account::factory()->for($this->user)->create(['name' => 'Everyday']);
    $account = Account::factory()->for($this->user)->create(['name' => 'Old Saver']);

    Livewire::actingAs($this->user)
        ->test(AccountManager::class)
        ->call('disableAccount', $account->id);

    $modalIds = Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->viewData('accounts')->pluck('id')->all();

    expect($account->fresh()->status)->toBe(AccountStatus::Inactive)
        ->and($account->fresh()->disabled_at->toDateString())->toBe('2026-05-12')
        ->and($modalIds)->toBe([$active->id]);

    $plannerIds = Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->viewData('accounts')->pluck('id')->all();

    expect($plannerIds)->toBe([$active->id]);

    Livewire::actingAs($this->user)
        ->test(ImportBank::class)
        ->assertViewHas('accounts', fn ($accounts) => $accounts->pluck('id')->all() === [$active->id]);
});

test('clears disabled_at when an account is re-enabled', function () {
    $account = Account::factory()->for($this->user)->create(['status' => AccountStatus::Inactive, 'disabled_at' => '2026-05-01']);

    Livewire::actingAs($this->user)
        ->test(AccountManager::class)
        ->call('enableAccount', $account->id);

    $modalIds = Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->viewData('accounts')->pluck('id')->all();

    expect($account->fresh()->status)->toBe(AccountStatus::Active)
        ->and($account->fresh()->disabled_at)->toBeNull()
        ->and($modalIds)->toBe([$account->id]);
});

test('keeps the disable date when an already disabled account is disabled again', function () {
    $account = Account::factory()->for($this->user)->create(['status' => AccountStatus::Inactive, 'disabled_at' => '2026-05-01']);

    Livewire::actingAs($this->user)
        ->test(AccountManager::class)
        ->call('disableAccount', $account->id);

    expect($account->fresh()->disabled_at->toDateString())->toBe('2026-05-01');
});

test('keeps every transaction when a bank-linked account is unlinked', function () {
    $account = lifecycleLinkedAccount($this->user);
    Transaction::factory()->count(30)->for($this->user)->for($account)->create();

    Livewire::actingAs($this->user)
        ->test(AccountManager::class)
        ->call('unlinkAccount', $account->id);

    $account->refresh();

    expect($account->redbarkAccount()->exists())->toBeFalse()
        ->and(RedbarkAccount::query()->count())->toBe(1)
        ->and(RedbarkAccount::query()->sole()->account_id)->toBeNull()
        ->and(Transaction::query()->where('account_id', $account->id)->count())->toBe(30)
        ->and($account->import_source)->toBe(ImportSource::Manual)
        ->and($account->isManuallyBalanced())->toBeTrue();
});

test('does not ask to set up an account again after it is unlinked', function () {
    $account = lifecycleLinkedAccount($this->user);

    Livewire::actingAs($this->user)
        ->test(AccountManager::class)
        ->call('unlinkAccount', $account->id);

    expect(RedbarkAccount::query()->needsSetup()->exists())->toBeFalse();
});

test('cannot disable or unlink another users account', function () {
    $other = lifecycleLinkedAccount(User::factory()->create(), 'Theirs');

    Livewire::actingAs($this->user)
        ->test(AccountManager::class)
        ->call('disableAccount', $other->id)
        ->call('unlinkAccount', $other->id);

    $other->refresh();

    expect($other->status)->toBe(AccountStatus::Active)
        ->and($other->disabled_at)->toBeNull()
        ->and($other->redbarkAccount()->exists())->toBeTrue()
        ->and($other->import_source)->toBe(ImportSource::Redbark);
});

test('records an audit event when an account is disabled', function () {
    $account = Account::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->test(AccountManager::class)
        ->call('disableAccount', $account->id);

    $event = AuditEvent::query()->where('action', 'livewire.account-manager.disableAccount')->sole();

    expect($event->user_id)->toBe($this->user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success);
});

test('records an audit event when an account is unlinked', function () {
    $account = lifecycleLinkedAccount($this->user);

    Livewire::actingAs($this->user)
        ->test(AccountManager::class)
        ->call('unlinkAccount', $account->id);

    expect(AuditEvent::query()->where('action', 'livewire.account-manager.unlinkAccount')->where('user_id', $this->user->id)->exists())->toBeTrue();
});

test('still offers a disabled account in the edit form of a transaction it holds', function () {
    $account = Account::factory()->for($this->user)->create(['status' => AccountStatus::Inactive, 'disabled_at' => '2026-05-01']);
    $transaction = Transaction::factory()->for($this->user)->for($account)->manual()->create();

    $component = Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id);

    expect($component->viewData('accounts')->pluck('id')->all())->toBe([$account->id]);
});

test('lists transactions of a disabled account only when it is picked explicitly', function () {
    $active = Account::factory()->for($this->user)->create();
    $disabled = Account::factory()->for($this->user)->create(['status' => AccountStatus::Inactive, 'disabled_at' => '2026-05-01']);
    Transaction::factory()->for($this->user)->for($active)->create(['post_date' => '2026-05-10', 'description' => 'Kept lunch']);
    Transaction::factory()->for($this->user)->for($disabled)->create(['post_date' => '2026-05-10', 'description' => 'Hidden dinner']);

    $default = Livewire::actingAs($this->user)->test(TransactionList::class)->set('period', 'this-month');
    $picked = Livewire::actingAs($this->user)->test(TransactionList::class)->set('period', 'this-month')->set('account', $disabled->id);

    expect($default->viewData('accounts')->pluck('id')->all())->not->toContain($disabled->id)
        ->and($picked->viewData('accounts')->pluck('id')->all())->toContain($disabled->id);

    $default->assertSee('Kept lunch')->assertDontSee('Hidden dinner');
    $picked->assertSee('Hidden dinner')->assertDontSee('Kept lunch');
});

test('still lists transactions of a closed account by default', function () {
    $closed = Account::factory()->for($this->user)->closed()->create();
    Transaction::factory()->for($this->user)->for($closed)->create(['post_date' => '2026-05-10', 'description' => 'Closed account coffee']);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('period', 'this-month')
        ->assertSee('Closed account coffee');
});

test('opens the planner on an active account when the primary account is disabled', function () {
    $active = Account::factory()->for($this->user)->create(['name' => 'Everyday']);
    $primary = Account::factory()->for($this->user)->create(['name' => 'Old Saver']);
    $this->user->update(['primary_account_id' => $primary->id]);
    $primary->update(['status' => AccountStatus::Inactive, 'disabled_at' => '2026-05-01']);

    $planner = Livewire::actingAs($this->user)->test(PlannedTransactionManager::class);

    expect($planner->get('accountId'))->toBe($active->id)
        ->and($planner->viewData('accounts')->pluck('id')->all())->toBe([$active->id])
        ->and($this->user->fresh()->primary_account_id)->toBe($primary->id);
});

test('keeps an active primary account as the planner default', function () {
    Account::factory()->for($this->user)->create(['name' => 'Aardvark']);
    $primary = Account::factory()->for($this->user)->create(['name' => 'Zebra']);
    $this->user->update(['primary_account_id' => $primary->id]);

    expect(Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)->get('accountId'))->toBe($primary->id);
});

test('reaches the history of a disabled account from the accounts page', function () {
    $disabled = Account::factory()->for($this->user)->create(['status' => AccountStatus::Inactive, 'disabled_at' => '2026-05-01']);
    Transaction::factory()->for($this->user)->for($disabled)->create(['post_date' => '2026-04-10', 'description' => 'Old saver coffee']);

    $html = Livewire::actingAs($this->user)->test(AccountManager::class)->html();

    $document = new DOMDocument;
    @$document->loadHTML($html);
    $href = new DOMXPath($document)->evaluate('string(//*[@data-test="account-history-'.$disabled->id.'"]/@href)');
    parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

    expect($href)->toContain('/transactions')
        ->and($query)->toMatchArray(['account' => (string) $disabled->id, 'period' => 'all']);

    Livewire::actingAs($this->user)
        ->withQueryParams($query)
        ->test(TransactionList::class)
        ->assertSee('Old saver coffee');
});

test('does not offer the history link for an active account', function () {
    $active = Account::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->test(AccountManager::class)
        ->assertDontSeeHtml('account-history-'.$active->id);
});

test('saves an edit to a transfer whose destination has since been disabled', function () {
    $from = Account::factory()->for($this->user)->create();
    $to = Account::factory()->for($this->user)->create();
    $debit = Transaction::factory()->for($this->user)->for($from)->manual()->create([
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'description' => 'savings transfer',
        'post_date' => '2026-05-10',
    ]);
    $credit = Transaction::factory()->for($this->user)->for($to)->manual()->create([
        'amount' => 10000,
        'direction' => TransactionDirection::Credit,
        'description' => 'savings transfer',
        'post_date' => '2026-05-10',
        'transfer_pair_id' => $debit->id,
    ]);
    $debit->update(['transfer_pair_id' => $credit->id]);
    $to->update(['status' => AccountStatus::Inactive, 'disabled_at' => '2026-05-11']);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('notes', 'kept the destination')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    expect(Transaction::findCurrentVersion($debit->id, $this->user->id)->notes)->toBe('kept the destination');
});

test('saves an edit to a planned transfer whose destination has since been disabled', function () {
    $from = Account::factory()->for($this->user)->create();
    $to = Account::factory()->for($this->user)->create();
    $planned = PlannedTransaction::factory()->for($this->user)->create([
        'account_id' => $from->id,
        'transfer_to_account_id' => $to->id,
        'start_date' => '2026-06-01',
    ]);
    $to->update(['status' => AccountStatus::Inactive, 'disabled_at' => '2026-05-11']);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id)
        ->call('save')
        ->assertHasNoErrors();
});

test('refuses a different disabled account as a transfer destination', function () {
    $from = Account::factory()->for($this->user)->create();
    $to = Account::factory()->for($this->user)->create();
    $other = Account::factory()->for($this->user)->create(['status' => AccountStatus::Inactive, 'disabled_at' => '2026-05-01']);
    $debit = Transaction::factory()->for($this->user)->for($from)->manual()->create(['amount' => 10000, 'direction' => TransactionDirection::Debit]);
    $credit = Transaction::factory()->for($this->user)->for($to)->manual()->create([
        'amount' => 10000,
        'direction' => TransactionDirection::Credit,
        'transfer_pair_id' => $debit->id,
    ]);
    $debit->update(['transfer_pair_id' => $credit->id]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('transferToAccountId', $other->id)
        ->call('save')
        ->assertHasErrors(['transferToAccountId']);
});

test('refuses another users account as a transfer destination even when it matches the opened id', function () {
    $stranger = Account::factory()->for(User::factory()->create())->create();
    $from = Account::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-05-10')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '10 sneaky')
        ->set('accountId', $from->id)
        ->set('transferToAccountId', $stranger->id)
        ->call('save')
        ->assertHasErrors(['transferToAccountId']);
});

test('writes nothing for an account unlinked after the sync loaded its accounts', function () {
    Queue::fake();
    $account = lifecycleLinkedAccount($this->user);
    $account->update(['balance' => 100]);
    $redbarkAccount = $account->redbarkAccount;
    $redbarkAccount->update([
        'current_balance' => 99999,
        'raw_transactions_payload' => [[
            'id' => 'rb_txn_1',
            'status' => 'posted',
            'date' => '2026-05-10',
            'postDate' => '2026-05-10',
            'description' => 'WOOLWORTHS',
            'amount' => '-42.50',
        ]],
    ]);
    $job = new SyncRedbarkFeedJob($redbarkAccount->feed, RefreshTrigger::Manual);
    $unlinked = false;

    Account::query()->getConnection()->listen(function ($query) use (&$unlinked, $redbarkAccount): void {
        if (! $unlinked && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "accounts"')) {
            $unlinked = true;
            RedbarkAccount::query()->whereKey($redbarkAccount->id)->update(['account_id' => null, 'ignored' => true]);
        }
    });

    new ReflectionMethod($job, 'applyToAccounts')->invoke($job, app(TransactionIngestor::class), app(RedbarkTransactionMatcher::class));

    expect($unlinked)->toBeTrue()
        ->and($account->fresh()->balance)->toBe(100)
        ->and($account->fresh()->import_source)->toBe(ImportSource::Redbark)
        ->and(Transaction::query()->where('account_id', $account->id)->count())->toBe(0);
});
