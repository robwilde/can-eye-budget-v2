<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\NewTransfer;
use App\Enums\AccountClass;
use App\Enums\AuditOutcome;
use App\Enums\TransactionDirection;
use App\Enums\TransactionKind;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transfers\TransferKinds;
use App\Services\Transfers\TransferLinker;
use App\Services\UserCategoryProvisioner;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo('2026-10-15');
    $this->user = User::factory()->create();
    app(UserCategoryProvisioner::class)->provision($this->user);
    $this->checking = Account::factory()->for($this->user)->create(['type' => AccountClass::Transaction]);
});

function kindModalTransfer(User $user, Account $from, Account $to): Transaction
{
    return app(TransferLinker::class)->create(new NewTransfer(
        userId: $user->id, fromAccountId: $from->id, toAccountId: $to->id,
        amount: 50000, description: 'move money', postDate: '2026-10-10',
    ))->fresh();
}

function kindModalExpense(User $user, Account $account): Transaction
{
    return Transaction::factory()->for($user)->manual()->create([
        'account_id' => $account->id, 'direction' => TransactionDirection::Debit, 'amount' => 2500,
        'post_date' => '2026-10-10', 'description' => 'SOMETHING', 'transfer_pair_id' => null,
    ]);
}

it('cannot change the kind of another users transaction', function () {
    $stranger = User::factory()->create();
    $theirs = kindModalExpense($stranger, Account::factory()->for($stranger)->create());

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->set('editingTransactionId', $theirs->id)
        ->set('transactionType', 'expense')
        ->set('accountId', $this->checking->id)
        ->set('descriptionInput', '25 SOMETHING')
        ->set('date', '2026-10-10')
        ->set('kind', TransactionKind::OneTime->value)
        ->call('save')
        ->assertHasErrors(['kind']);

    expect($theirs->fresh()->kind)->toBe(TransactionKind::Standard)
        ->and(Transaction::withTrashed()->where('parent_transaction_id', $theirs->id)->exists())->toBeFalse();
});

it('records an audit event when a transaction kind changes', function () {
    $expense = kindModalExpense($this->user, $this->checking);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $expense->id)
        ->assertSet('kind', 'standard')
        ->set('kind', TransactionKind::OneTime->value)
        ->call('save')
        ->assertHasNoErrors();

    $current = Transaction::query()->where('user_id', $this->user->id)->current()->sole();
    $event = AuditEvent::query()->where('action', 'livewire.transaction-modal.save')->sole();

    expect($current->kind)->toBe(TransactionKind::OneTime)
        ->and($event->user_id)->toBe($this->user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success);
});

it('rejects a kind that is not one of the enum values', function () {
    $expense = kindModalExpense($this->user, $this->checking);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $expense->id)
        ->set('kind', 'bogus')
        ->call('save')
        ->assertHasErrors(['kind']);

    expect($expense->fresh()->kind)->toBe(TransactionKind::Standard);
});

it('shows the kind select for an existing transaction only', function () {
    $expense = kindModalExpense($this->user, $this->checking);

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-10-10')
        ->assertDontSeeHtml('data-testid="transaction-kind"');

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $expense->id)
        ->assertSeeHtml('data-testid="transaction-kind"');
});

it('keeps the kind through an edit that does not touch it', function () {
    $card = Account::factory()->for($this->user)->create(['type' => AccountClass::CreditCard]);
    $debit = kindModalTransfer($this->user, $this->checking, $card);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('notes', 'renamed')
        ->call('save')
        ->assertHasNoErrors();

    $current = Transaction::query()->where('user_id', $this->user->id)->current()->get()->keyBy('direction');

    expect($current[TransactionDirection::Debit->value]->kind)->toBe(TransactionKind::CreditCardPayment)
        ->and($current[TransactionDirection::Credit->value]->kind)->toBe(TransactionKind::FundsMovement);
});

it('derives the kinds again when an edit moves a transfer to other accounts', function () {
    $card = Account::factory()->for($this->user)->create(['type' => AccountClass::CreditCard]);
    $loan = Account::factory()->for($this->user)->create(['type' => AccountClass::Loan]);
    $debit = kindModalTransfer($this->user, $this->checking, $card);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('transferToAccountId', $loan->id)
        ->call('save')
        ->assertHasNoErrors();

    $current = Transaction::query()->where('user_id', $this->user->id)->current()->get()->keyBy('direction');

    expect($current[TransactionDirection::Debit->value]->kind)->toBe(TransactionKind::LoanPayment)
        ->and($current[TransactionDirection::Credit->value]->kind)->toBe(TransactionKind::FundsMovement);
});

it('saves a kind chosen for the edited leg of a transfer', function () {
    $card = Account::factory()->for($this->user)->create(['type' => AccountClass::CreditCard]);
    $debit = kindModalTransfer($this->user, $this->checking, $card);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('kind', TransactionKind::FundsMovement->value)
        ->call('save')
        ->assertHasNoErrors();

    $current = Transaction::query()->where('user_id', $this->user->id)->current()->get()->keyBy('direction');

    expect($current[TransactionDirection::Debit->value]->kind)->toBe(TransactionKind::FundsMovement)
        ->and($current[TransactionDirection::Credit->value]->kind)->toBe(TransactionKind::FundsMovement);
});

it('derives the kinds when a transaction is converted to a transfer', function () {
    $loan = Account::factory()->for($this->user)->create(['type' => AccountClass::Loan]);
    $expense = kindModalExpense($this->user, $this->checking);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $expense->id)
        ->set('transactionType', 'transfer')
        ->set('transferToAccountId', $loan->id)
        ->call('save')
        ->assertHasNoErrors();

    $current = Transaction::query()->where('user_id', $this->user->id)->current()->get()->keyBy('direction');

    expect($current[TransactionDirection::Debit->value]->kind)->toBe(TransactionKind::LoanPayment)
        ->and($current[TransactionDirection::Credit->value]->kind)->toBe(TransactionKind::FundsMovement);
});

it('resets the kinds when a transfer is converted back to an expense', function () {
    $loan = Account::factory()->for($this->user)->create(['type' => AccountClass::Loan]);
    $debit = kindModalTransfer($this->user, $this->checking, $loan);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('transactionType', 'expense')
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::query()->where('user_id', $this->user->id)->current()->sole()->kind)->toBe(TransactionKind::Standard);
});

it('takes the Investment Contributions category off when the kind becomes a plain transfer', function () {
    $investment = Account::factory()->for($this->user)->create(['type' => AccountClass::Investment]);
    $debit = kindModalTransfer($this->user, $this->checking, $investment);
    $category = Category::query()->where('user_id', $this->user->id)->where('name', TransferKinds::INVESTMENT_CATEGORY)->sole();

    expect($debit->kind)->toBe(TransactionKind::InvestmentContribution)
        ->and($debit->category_id)->toBe($category->id);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->assertSet('categoryId', $category->id)
        ->set('kind', TransactionKind::FundsMovement->value)
        ->call('save')
        ->assertHasNoErrors();

    $current = Transaction::query()->where('user_id', $this->user->id)->current()->where('direction', TransactionDirection::Debit)->sole();

    expect($current->kind)->toBe(TransactionKind::FundsMovement)
        ->and($current->category_id)->toBeNull();
});

it('keeps a custom category when the kind becomes a plain transfer', function () {
    $investment = Account::factory()->for($this->user)->create(['type' => AccountClass::Investment]);
    $debit = kindModalTransfer($this->user, $this->checking, $investment);
    $custom = Category::factory()->for($this->user)->create(['name' => 'Shares']);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('categoryId', $custom->id)
        ->set('kind', TransactionKind::FundsMovement->value)
        ->call('save')
        ->assertHasNoErrors();

    $current = Transaction::query()->where('user_id', $this->user->id)->current()->where('direction', TransactionDirection::Debit)->sole();

    expect($current->kind)->toBe(TransactionKind::FundsMovement)
        ->and($current->category_id)->toBe($custom->id);
});

it('keeps the credit leg uncategorised when only a note on an investment contribution is edited', function () {
    $investment = Account::factory()->for($this->user)->create(['type' => AccountClass::Investment]);
    $debit = kindModalTransfer($this->user, $this->checking, $investment);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('notes', 'only a note')
        ->call('save')
        ->assertHasNoErrors();

    $current = Transaction::query()->where('user_id', $this->user->id)->current()->get()->keyBy('direction');

    expect($current[TransactionDirection::Debit->value]->category_id)->not->toBeNull()
        ->and($current[TransactionDirection::Credit->value]->category_id)->toBeNull();
});

it('takes the Investment Contributions category off when an edit moves the transfer to a savings account', function () {
    $investment = Account::factory()->for($this->user)->create(['type' => AccountClass::Investment]);
    $savings = Account::factory()->for($this->user)->create(['type' => AccountClass::Savings]);
    $debit = kindModalTransfer($this->user, $this->checking, $investment);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('transferToAccountId', $savings->id)
        ->call('save')
        ->assertHasNoErrors();

    $current = Transaction::query()->where('user_id', $this->user->id)->current()->get()->keyBy('direction');

    expect($current[TransactionDirection::Debit->value]->kind)->toBe(TransactionKind::FundsMovement)
        ->and($current[TransactionDirection::Debit->value]->category_id)->toBeNull()
        ->and($current[TransactionDirection::Credit->value]->category_id)->toBeNull();
});

it('keeps a custom category when an edit moves an investment contribution to a savings account', function () {
    $investment = Account::factory()->for($this->user)->create(['type' => AccountClass::Investment]);
    $savings = Account::factory()->for($this->user)->create(['type' => AccountClass::Savings]);
    $custom = Category::factory()->for($this->user)->create(['name' => 'Shares']);
    $debit = kindModalTransfer($this->user, $this->checking, $investment);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('categoryId', $custom->id)
        ->set('transferToAccountId', $savings->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::query()->where('user_id', $this->user->id)->current()->where('direction', TransactionDirection::Debit)->sole()->category_id)->toBe($custom->id);
});

it('keeps the credit leg category when the kind becomes a plain transfer', function () {
    $investment = Account::factory()->for($this->user)->create(['type' => AccountClass::Investment]);
    $custom = Category::factory()->for($this->user)->create(['name' => 'Shares']);
    $debit = kindModalTransfer($this->user, $this->checking, $investment);
    Transaction::query()->whereKey($debit->transfer_pair_id)->update(['category_id' => $custom->id]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('kind', TransactionKind::FundsMovement->value)
        ->call('save')
        ->assertHasNoErrors();

    $current = Transaction::query()->where('user_id', $this->user->id)->current()->get()->keyBy('direction');

    expect($current[TransactionDirection::Debit->value]->category_id)->toBeNull()
        ->and($current[TransactionDirection::Credit->value]->category_id)->toBe($custom->id);
});

it('keeps the credit leg category when an edit moves an investment contribution to a savings account', function () {
    $investment = Account::factory()->for($this->user)->create(['type' => AccountClass::Investment]);
    $savings = Account::factory()->for($this->user)->create(['type' => AccountClass::Savings]);
    $custom = Category::factory()->for($this->user)->create(['name' => 'Shares']);
    $debit = kindModalTransfer($this->user, $this->checking, $investment);
    Transaction::query()->whereKey($debit->transfer_pair_id)->update(['category_id' => $custom->id]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('transferToAccountId', $savings->id)
        ->call('save')
        ->assertHasNoErrors();

    $current = Transaction::query()->where('user_id', $this->user->id)->current()->get()->keyBy('direction');

    expect($current[TransactionDirection::Debit->value]->category_id)->toBeNull()
        ->and($current[TransactionDirection::Credit->value]->category_id)->toBe($custom->id);
});
