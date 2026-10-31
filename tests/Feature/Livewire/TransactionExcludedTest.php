<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\ImportSource;
use App\Enums\TransactionDirection;
use App\Livewire\CategoryEditor;
use App\Livewire\TransactionList;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\AccountValuation;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionEmail;
use App\Models\User;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use App\Services\TransactionIdempotency;
use App\Support\Transactions\CategoryAttribution;
use App\Support\Transactions\TransactionTotals;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Ramsey\Uuid\Uuid;

beforeEach(function (): void {
    $this->travelTo('2026-10-15');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function excludableRow(User $user, Account $account, array $overrides = []): Transaction
{
    return Transaction::factory()->manual()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'description' => 'DENTIST '.fake()->unique()->numerify('####'),
        'post_date' => '2026-10-10',
        'transfer_pair_id' => null,
    ], $overrides));
}

it('leaves excluded transactions out of totals but lists them', function () {
    $category = Category::factory()->for($this->user)->create();
    $counted = excludableRow($this->user, $this->account, ['amount' => 1200, 'category_id' => $category->id]);
    $excluded = excludableRow($this->user, $this->account, ['amount' => 5000, 'category_id' => $category->id, 'excluded' => true]);

    $attributed = CategoryAttribution::query($this->user->id, true)->where('direction', 'debit')->sum('amount');
    $countable = Transaction::query()->where('user_id', $this->user->id)->countable()->pluck('id')->all();
    $totals = TransactionTotals::summarise(Transaction::query()->where('user_id', $this->user->id)->current(), $this->user->id);

    expect((int) $attributed)->toBe(1200)
        ->and($countable)->toBe([$counted->id])
        ->and($totals['expenses'])->toBe(1200);

    Livewire::actingAs($this->user)
        ->withQueryParams(['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31'])
        ->test(TransactionList::class)
        ->assertSee($excluded->description)
        ->assertSeeHtml('data-testid="excluded-'.$excluded->id.'"')
        ->assertDontSeeHtml('data-testid="excluded-'.$counted->id.'"');
});

it('leaves excluded split lines out of category attribution', function () {
    $category = Category::factory()->for($this->user)->create();
    $split = excludableRow($this->user, $this->account, ['amount' => 5000, 'excluded' => true]);
    $split->splits()->create(['category_id' => $category->id, 'amount' => 5000]);

    expect((int) CategoryAttribution::query($this->user->id, true)->sum('amount'))->toBe(0);
});

it('creates one transaction when the new transaction form is submitted twice', function () {
    Str::createUuidsUsing(fn () => Uuid::fromString('11111111-1111-4111-8111-111111111111'));

    try {
        $submit = fn () => Livewire::actingAs($this->user)
            ->test(TransactionModal::class)
            ->dispatch('open-transaction-modal', date: '2026-10-12')
            ->set('transactionType', 'expense')
            ->set('descriptionInput', '42.50 coffee')
            ->set('accountId', $this->account->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        $submit();
        $submit();
    } finally {
        Str::createUuidsNormally();
    }

    expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

it('creates a second transaction for a second submission with a fresh token', function () {
    $modal = Livewire::actingAs($this->user)->test(TransactionModal::class);

    foreach (['10 lunch', '10 lunch'] as $input) {
        $modal->dispatch('open-transaction-modal', date: '2026-10-12')
            ->set('transactionType', 'expense')
            ->set('descriptionInput', $input)
            ->set('accountId', $this->account->id)
            ->call('save');
    }

    expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(2)
        ->and(Transaction::query()->whereNotNull('idempotency_key')->distinct()->count('idempotency_key'))->toBe(2);
});

it('stores the form submission token as the idempotency key', function () {
    $modal = Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-10-12')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '9 snack')
        ->set('accountId', $this->account->id);
    $token = $modal->get('submissionToken');

    $modal->call('save')->assertHasNoErrors();

    expect(Transaction::query()->where('user_id', $this->user->id)->sole()->idempotency_key)->toBe($token);
});

it('does not let the client set the submission token', function () {
    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->set('submissionToken', 'forged');
})->throws(CannotUpdateLockedPropertyException::class);

it('returns the first transaction when a repeat races the insert', function () {
    $key = 'race-key';
    $first = excludableRow($this->user, $this->account);
    $attempt = new Transaction([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'description' => 'DENTIST',
        'post_date' => '2026-10-10',
    ]);
    $raced = false;

    Transaction::query()->getConnection()->listen(function ($query) use (&$raced, $first, $key): void {
        if (! $raced && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'idempotency_key')) {
            $raced = true;
            Transaction::query()->whereKey($first->id)->update(['idempotency_key' => $key]);
        }
    });

    $result = app(TransactionIdempotency::class)->ingestOnce($attempt, $key);

    expect($raced)->toBeTrue()
        ->and($result->id)->toBe($first->id)
        ->and(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

it('scopes idempotency keys to the user', function () {
    $other = User::factory()->create();
    $theirs = excludableRow($other, Account::factory()->for($other)->create(), ['idempotency_key' => 'shared-key']);
    $attempt = new Transaction([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'description' => 'MINE',
        'post_date' => '2026-10-10',
    ]);

    $result = app(TransactionIdempotency::class)->ingestOnce($attempt, 'shared-key');

    expect($result->id)->not->toBe($theirs->id)
        ->and($result->user_id)->toBe($this->user->id);
});

it('keeps the excluded flag on a new version', function () {
    $transaction = excludableRow($this->user, $this->account, ['excluded' => true]);

    $child = $transaction->createChild(['notes' => 'edited']);

    expect($child->excluded)->toBeTrue()
        ->and($child->fresh()->excluded)->toBeTrue();
});

it('does not copy the idempotency key onto a new version', function () {
    $transaction = excludableRow($this->user, $this->account, ['idempotency_key' => 'form-token']);

    $child = $transaction->createChild(['notes' => 'edited']);

    expect($child->idempotency_key)->toBeNull()
        ->and($transaction->fresh()->idempotency_key)->toBe('form-token');
});

it('excludes a transaction from the list as a new version and includes it again', function () {
    $transaction = excludableRow($this->user, $this->account);

    $list = Livewire::actingAs($this->user)->test(TransactionList::class);

    $list->call('toggleExcluded', $transaction->id, true);

    $child = Transaction::query()->where('parent_transaction_id', $transaction->id)->sole();
    expect($child->excluded)->toBeTrue()
        ->and($transaction->fresh()->excluded)->toBeFalse()
        ->and(Transaction::query()->where('user_id', $this->user->id)->current()->pluck('id')->all())->toBe([$child->id]);

    $list->call('toggleExcluded', $child->id, false);

    expect(Transaction::findCurrentVersion($transaction->id, $this->user->id)->excluded)->toBeFalse();
});

it('keeps splits and linked emails on the current version when exclusion is toggled', function () {
    $food = Category::factory()->for($this->user)->create();
    $fun = Category::factory()->for($this->user)->create();
    $transaction = excludableRow($this->user, $this->account, ['amount' => 5000]);
    $transaction->splits()->createMany([
        ['category_id' => $food->id, 'amount' => 3000, 'position' => 1],
        ['category_id' => $fun->id, 'amount' => 2000, 'position' => 2],
    ]);
    TransactionEmail::factory()->create(['user_id' => $this->user->id, 'transaction_id' => $transaction->id, 'gmail_message_id' => 'receipt-1@mail']);

    $list = Livewire::actingAs($this->user)->test(TransactionList::class);
    $list->call('toggleExcluded', $transaction->id, true);

    $excluded = Transaction::findCurrentVersion($transaction->id, $this->user->id);

    expect($excluded->excluded)->toBeTrue()
        ->and($excluded->splits->pluck('amount', 'category_id')->all())->toBe([$food->id => 3000, $fun->id => 2000])
        ->and($excluded->emails->pluck('gmail_message_id')->all())->toBe(['receipt-1@mail']);

    $list->call('toggleExcluded', $excluded->id, false);

    $included = Transaction::findCurrentVersion($transaction->id, $this->user->id);

    expect($included->excluded)->toBeFalse()
        ->and($included->splits->pluck('amount', 'category_id')->all())->toBe([$food->id => 3000, $fun->id => 2000])
        ->and($included->emails->pluck('gmail_message_id')->all())->toBe(['receipt-1@mail'])
        ->and(CategoryAttribution::query($this->user->id, true)->where('category_id', $food->id)->sum('amount'))->toEqual(3000);
});

it('toggles the latest version when given an older version id', function () {
    $transaction = excludableRow($this->user, $this->account);
    $latest = $transaction->createChild(['notes' => 'edited'])->createChild(['notes' => 'edited again']);

    Livewire::actingAs($this->user)->test(TransactionList::class)->call('toggleExcluded', $transaction->id, true);

    $current = Transaction::query()->where('user_id', $this->user->id)->current()->get();

    expect($current)->toHaveCount(1)
        ->and($current->sole()->parent_transaction_id)->toBe($latest->id)
        ->and($current->sole()->excluded)->toBeTrue()
        ->and($current->sole()->notes)->toBe('edited again');
});

it('cannot exclude another users transaction', function () {
    $other = User::factory()->create();
    $foreign = excludableRow($other, Account::factory()->for($other)->create());

    expect(fn () => Livewire::actingAs($this->user)->test(TransactionList::class)->call('toggleExcluded', $foreign->id, true))
        ->toThrow(ModelNotFoundException::class);

    expect($foreign->fresh()->excluded)->toBeFalse()
        ->and(Transaction::query()->where('parent_transaction_id', $foreign->id)->exists())->toBeFalse();
});

it('leaves an excluded state it already has alone', function () {
    $transaction = excludableRow($this->user, $this->account, ['excluded' => true]);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->call('toggleExcluded', $transaction->id, true)
        ->assertNotDispatched('transaction-saved');

    expect(Transaction::query()->where('parent_transaction_id', $transaction->id)->exists())->toBeFalse();
});

it('refuses to exclude a transfer leg and does not offer the action on it', function () {
    $other = Account::factory()->for($this->user)->create();
    $debit = excludableRow($this->user, $this->account);
    $credit = excludableRow($this->user, $other, ['direction' => TransactionDirection::Credit, 'transfer_pair_id' => $debit->id]);
    $debit->update(['transfer_pair_id' => $credit->id]);
    $plain = excludableRow($this->user, $this->account);

    Livewire::actingAs($this->user)
        ->withQueryParams(['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31'])
        ->test(TransactionList::class)
        ->assertSeeHtml('data-testid="toggle-excluded-'.$plain->id.'"')
        ->assertDontSeeHtml('data-testid="toggle-excluded-'.$debit->id.'"')
        ->call('toggleExcluded', $debit->id, true)
        ->assertNotDispatched('transaction-saved');

    expect(Transaction::query()->where('parent_transaction_id', $debit->id)->exists())->toBeFalse()
        ->and($debit->fresh()->excluded)->toBeFalse();
});

it('lets an excluded transfer leg be included again', function () {
    $other = Account::factory()->for($this->user)->create();
    $debit = excludableRow($this->user, $this->account, ['excluded' => true]);
    $credit = excludableRow($this->user, $other, ['direction' => TransactionDirection::Credit, 'transfer_pair_id' => $debit->id]);
    $debit->update(['transfer_pair_id' => $credit->id]);

    Livewire::actingAs($this->user)
        ->withQueryParams(['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31'])
        ->test(TransactionList::class)
        ->assertSeeHtml('data-testid="toggle-excluded-'.$debit->id.'"')
        ->call('toggleExcluded', $debit->id, false)
        ->assertDispatched('transaction-saved');

    expect(Transaction::findCurrentVersion($debit->id, $this->user->id)->excluded)->toBeFalse();
});

it('refreshes the list after toggling exclusion', function () {
    $transaction = excludableRow($this->user, $this->account);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->call('toggleExcluded', $transaction->id, true)
        ->assertDispatched('transaction-saved');
});

it('keeps an already excluded row excluded when its notes are edited in the modal', function () {
    $transaction = excludableRow($this->user, $this->account, ['excluded' => true]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertSet('excluded', true)
        ->set('notes', 'edited')
        ->call('save')
        ->assertHasNoErrors();

    $current = Transaction::findCurrentVersion($transaction->id, $this->user->id);

    expect($current->notes)->toBe('edited')
        ->and($current->excluded)->toBeTrue();
});

it('still counts excluded transactions when warning before a category is deleted', function () {
    $category = Category::factory()->for($this->user)->create();
    excludableRow($this->user, $this->account, ['category_id' => $category->id, 'excluded' => true]);

    Livewire::actingAs($this->user)
        ->test(CategoryEditor::class)
        ->call('confirmDelete', $category->id)
        ->assertSet('deletingTransactionCount', 1);
});

it('records an audit event when a transaction is excluded', function () {
    $transaction = excludableRow($this->user, $this->account);

    Livewire::actingAs($this->user)->test(TransactionList::class)->call('toggleExcluded', $transaction->id, true);

    $event = AuditEvent::query()->where('action', 'livewire.transaction-list.toggleExcluded')->sole();

    expect($event->user_id)->toBe($this->user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success);
});

it('excludes a transaction from the edit modal', function () {
    $transaction = excludableRow($this->user, $this->account);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->assertSet('excluded', false)
        ->set('excluded', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::findCurrentVersion($transaction->id, $this->user->id)->excluded)->toBeTrue();
});

it('excludes a bank feed transaction from the edit modal', function () {
    $transaction = Transaction::factory()->fromRedbark()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'post_date' => '2026-10-10',
        'transfer_pair_id' => null,
    ]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('excluded', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::findCurrentVersion($transaction->id, $this->user->id)->excluded)->toBeTrue();
});

it('offers the exclude checkbox on a plain edit but not on a row that was a transfer', function () {
    $plain = excludableRow($this->user, $this->account);
    $other = Account::factory()->for($this->user)->create();
    $debit = excludableRow($this->user, $this->account);
    $credit = excludableRow($this->user, $other, ['direction' => TransactionDirection::Credit, 'transfer_pair_id' => $debit->id]);
    $debit->update(['transfer_pair_id' => $credit->id]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $plain->id)
        ->assertSeeHtml('data-testid="exclude-toggle"');

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('transactionType', 'expense')
        ->assertDontSeeHtml('data-testid="exclude-toggle"');
});

it('creates an excluded transaction from the add modal', function () {
    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-10-12')
        ->set('descriptionInput', '15 gift')
        ->set('accountId', $this->account->id)
        ->set('excluded', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::query()->where('user_id', $this->user->id)->sole()->excluded)->toBeTrue();
});

it('still moves the account balance for an excluded transaction', function () {
    $account = Account::factory()->for($this->user)->create(['balance' => 0, 'balance_source' => ImportSource::Manual]);
    AccountValuation::factory()->for($account)->opening()->create(['valued_on' => '2026-10-01', 'balance' => 100000]);
    excludableRow($this->user, $account, ['amount' => 5000, 'excluded' => true, 'post_date' => '2026-10-05']);

    app(AccountBalanceHistoryBuilder::class)->rebuild($account);

    expect($account->dailyBalances()->whereDate('balance_date', '2026-10-05')->sole()->end_balance)->toBe(95000);
});
