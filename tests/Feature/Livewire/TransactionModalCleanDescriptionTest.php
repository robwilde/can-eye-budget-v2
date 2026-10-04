<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CleanDescriptionSource;
use App\Enums\RuleActionType;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function editNamedRow(User $user, Account $account, ?string $name, ?string $typed): ?Transaction
{
    $transaction = Transaction::factory()->for($user)->for($account)->fromRedbark()->create([
        'clean_description' => 'Woolworths',
        'clean_description_source' => CleanDescriptionSource::Feed,
        'category_id' => null,
        'notes' => null,
    ]);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('cleanDescription', $typed ?? $name ?? '')
        ->set('notes', 'Weekly shop')
        ->call('save')
        ->assertHasNoErrors();

    return Transaction::query()->where('parent_transaction_id', $transaction->id)->first();
}

test('a name the person typed is stamped manual so imports never replace it', function () {
    $child = editNamedRow($this->user, $this->account, 'Woolworths', 'Weekly groceries');

    expect($child->clean_description)->toBe('Weekly groceries')
        ->and($child->clean_description_source)->toBe(CleanDescriptionSource::Manual);
});

test('the import source is kept when the person leaves the name alone', function () {
    $child = editNamedRow($this->user, $this->account, 'Woolworths', null);

    expect($child->clean_description)->toBe('Woolworths')
        ->and($child->clean_description_source)->toBe(CleanDescriptionSource::Feed);
});

test('the source is cleared when the person blanks the name', function () {
    $child = editNamedRow($this->user, $this->account, '', '');

    expect($child->clean_description)->toBeNull()
        ->and($child->clean_description_source)->toBeNull();
});

test('a renamed adopted row is stamped manual so the import name no longer shows', function () {
    $transaction = Transaction::factory()->for($this->user)->for($this->account)->create([
        'source' => TransactionSource::Csv,
        'description' => 'Direct Debit NIB',
        'clean_description' => 'Nib',
        'clean_description_source' => CleanDescriptionSource::Feed,
        'category_id' => null,
    ]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('descriptionInput', '19.99 Health insurance')
        ->call('save')
        ->assertHasNoErrors();

    $child = Transaction::query()->where('parent_transaction_id', $transaction->id)->sole();

    expect($child->description)->toBe('Health insurance')
        ->and($child->clean_description)->toBe('Health insurance')
        ->and($child->clean_description_source)->toBe(CleanDescriptionSource::Manual);
});

test('a notes-only save keeps the import name of an adopted row whose description has brackets', function () {
    $transaction = adoptedRow($this->user, $this->account, [
        'description' => 'WOOLWORTHS 1234 SYDNEY (AU)',
        'clean_description' => 'Woolworths',
        'clean_description_source' => CleanDescriptionSource::Feed,
    ]);

    $child = renameRow($this->user, $transaction, '12.34 WOOLWORTHS 1234 SYDNEY (AU)', ['notes' => 'Weekly shop']);

    expect($child->notes)->toBe('Weekly shop')
        ->and($child->clean_description)->toBe('Woolworths')
        ->and($child->clean_description_source)->toBe(CleanDescriptionSource::Feed);
});

test('a notes-only save keeps a name enrichment set after the modal opened', function () {
    $transaction = Transaction::factory()->for($this->user)->for($this->account)->fromRedbark()->create([
        'clean_description' => 'Woolworths',
        'clean_description_source' => CleanDescriptionSource::Derived,
        'category_id' => null,
        'notes' => null,
    ]);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id);

    $transaction->update([
        'clean_description' => 'Woolworths Group',
        'clean_description_source' => CleanDescriptionSource::Brand,
    ]);

    $component->set('notes', 'Weekly shop')->call('save')->assertHasNoErrors();

    $child = Transaction::query()->where('parent_transaction_id', $transaction->id)->sole();

    expect($child->notes)->toBe('Weekly shop')
        ->and($child->clean_description)->toBe('Woolworths Group')
        ->and($child->clean_description_source)->toBe(CleanDescriptionSource::Brand);
});

test('a rename typed after enrichment changed the name is still stamped manual', function () {
    $transaction = Transaction::factory()->for($this->user)->for($this->account)->fromRedbark()->create([
        'clean_description' => 'Woolworths',
        'clean_description_source' => CleanDescriptionSource::Derived,
        'category_id' => null,
    ]);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id);

    $transaction->update([
        'clean_description' => 'Woolworths Group',
        'clean_description_source' => CleanDescriptionSource::Brand,
    ]);

    $component->set('cleanDescription', 'Weekly groceries')->call('save')->assertHasNoErrors();

    $child = Transaction::query()->where('parent_transaction_id', $transaction->id)->sole();

    expect($child->clean_description)->toBe('Weekly groceries')
        ->and($child->clean_description_source)->toBe(CleanDescriptionSource::Manual);
});

function adoptedRow(User $user, Account $account, array $overrides = []): Transaction
{
    return Transaction::factory()->for($user)->for($account)->create($overrides + [
        'source' => TransactionSource::Csv,
        'description' => 'Direct Debit NIB',
        'clean_description' => 'Nib',
        'clean_description_source' => CleanDescriptionSource::Feed,
        'category_id' => null,
    ]);
}

function renameRow(User $user, Transaction $row, string $input, array $state = []): Transaction
{
    $component = Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)
        ->set('descriptionInput', $input);

    foreach ($state as $property => $value) {
        $component->set($property, $value);
    }

    $component->call('save')->assertHasNoErrors();

    return Transaction::query()->where('parent_transaction_id', $row->id)->orderByDesc('id')->firstOrFail();
}

test('a second rename of an adopted row keeps its manual clean name in step', function () {
    $first = renameRow($this->user, adoptedRow($this->user, $this->account), '19.99 Health insurance');
    $second = renameRow($this->user, $first, '19.99 Private cover');

    expect($first->clean_description)->toBe('Health insurance')
        ->and($second->description)->toBe('Private cover')
        ->and($second->clean_description)->toBe('Private cover')
        ->and($second->clean_description_source)->toBe(CleanDescriptionSource::Manual);
});

test('a rename keeps a manual clean name that differs from the description', function () {
    $row = adoptedRow($this->user, $this->account, [
        'description' => 'Health insurance',
        'clean_description' => 'My cover',
        'clean_description_source' => CleanDescriptionSource::Manual,
    ]);

    $child = renameRow($this->user, $row, '19.99 Private cover');

    expect($child->description)->toBe('Private cover')
        ->and($child->clean_description)->toBe('My cover')
        ->and($child->clean_description_source)->toBe(CleanDescriptionSource::Manual);
});

test('renaming an adopted row while converting it to a transfer names the new version', function () {
    $to = Account::factory()->for($this->user)->create();

    $child = renameRow($this->user, adoptedRow($this->user, $this->account), '50.00 Health transfer', [
        'transactionType' => 'transfer',
        'transferToAccountId' => $to->id,
    ]);

    expect($child->transfer_pair_id)->not->toBeNull()
        ->and($child->description)->toBe('Health transfer')
        ->and($child->clean_description)->toBe('Health transfer')
        ->and($child->clean_description_source)->toBe(CleanDescriptionSource::Manual);
});

test('renaming an adopted row while updating its transfer names the new version', function () {
    $to = Account::factory()->for($this->user)->create();
    $debit = renameRow($this->user, adoptedRow($this->user, $this->account), '50.00 Health transfer', [
        'transactionType' => 'transfer',
        'transferToAccountId' => $to->id,
    ]);

    $updated = renameRow($this->user, $debit, '50.00 Savings move');

    expect($updated->description)->toBe('Savings move')
        ->and($updated->clean_description)->toBe('Savings move')
        ->and($updated->clean_description_source)->toBe(CleanDescriptionSource::Manual);
});

test('renaming an adopted row while converting it back from a transfer names the new version', function () {
    $to = Account::factory()->for($this->user)->create();
    $debit = renameRow($this->user, adoptedRow($this->user, $this->account), '50.00 Health transfer', [
        'transactionType' => 'transfer',
        'transferToAccountId' => $to->id,
    ]);

    $expense = renameRow($this->user, $debit, '50.00 Savings move', ['transactionType' => 'expense']);

    expect($expense->transfer_pair_id)->toBeNull()
        ->and($expense->description)->toBe('Savings move')
        ->and($expense->clean_description)->toBe('Savings move')
        ->and($expense->clean_description_source)->toBe(CleanDescriptionSource::Manual);
});

function enrichedWhileOpen(Authenticatable $user, Account $account): array
{
    $transaction = Transaction::factory()->for($user)->for($account)->fromRedbark()->create([
        'clean_description' => 'Woolworths',
        'clean_description_source' => CleanDescriptionSource::Derived,
        'category_id' => null,
    ]);

    $component = Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id);

    $transaction->update([
        'clean_description' => 'Woolworths Group',
        'clean_description_source' => CleanDescriptionSource::Brand,
    ]);

    return [$transaction, $component];
}

test('categorise-matching leaves an import name out of the rule when the field was left alone', function () {
    [$transaction, $component] = enrichedWhileOpen($this->user, $this->account);
    $category = Category::factory()->create(['is_hidden' => false]);

    $component->set('categoryId', $category->id)->set('categoriseMatching', true)->call('save')->assertHasNoErrors();

    $rule = UserRule::query()->where('user_id', $this->user->id)->sole();
    $child = Transaction::query()->where('parent_transaction_id', $transaction->id)->sole();

    expect(collect($rule->actions)->firstWhere('type', RuleActionType::SetCleanDescription->value))->toBeNull()
        ->and($child->clean_description)->toBe('Woolworths Group')
        ->and($child->clean_description_source)->toBe(CleanDescriptionSource::Brand);
});

test('categorise-matching carries a name the person set when the field was left alone', function () {
    $transaction = Transaction::factory()->for($this->user)->for($this->account)->fromRedbark()->create([
        'clean_description' => 'Weekly groceries',
        'clean_description_source' => CleanDescriptionSource::Manual,
        'category_id' => null,
    ]);
    $category = Category::factory()->create(['is_hidden' => false]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('categoryId', $category->id)
        ->set('categoriseMatching', true)
        ->call('save')
        ->assertHasNoErrors();

    $rule = UserRule::query()->where('user_id', $this->user->id)->sole();

    expect(collect($rule->actions)->firstWhere('type', RuleActionType::SetCleanDescription->value)['value'])->toBe('Weekly groceries');
});

test('categorise-matching keeps the name the person typed in the rule', function () {
    [$transaction, $component] = enrichedWhileOpen($this->user, $this->account);
    $category = Category::factory()->create(['is_hidden' => false]);

    $component->set('cleanDescription', 'Weekly groceries')
        ->set('categoryId', $category->id)
        ->set('categoriseMatching', true)
        ->call('save')
        ->assertHasNoErrors();

    $rule = UserRule::query()->where('user_id', $this->user->id)->sole();
    $child = Transaction::query()->where('parent_transaction_id', $transaction->id)->sole();

    expect(collect($rule->actions)->firstWhere('type', RuleActionType::SetCleanDescription->value)['value'])->toBe('Weekly groceries')
        ->and($child->clean_description)->toBe('Weekly groceries');
});

test('a veto landing after the modal read the row never reaches the new version', function () {
    $transaction = Transaction::factory()->for($this->user)->for($this->account)->fromRedbark()->create([
        'description' => 'VISA WOOLWORTHS 1234 SYDNEY',
        'clean_description' => 'Woolworths Group',
        'clean_description_source' => CleanDescriptionSource::Brand,
        'category_id' => null,
    ]);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('notes', 'Weekly shop');

    $reads = 0;
    Transaction::retrieved(function (Transaction $loaded) use (&$reads, $transaction): void {
        if ($loaded->id !== $transaction->id || ++$reads !== 2) {
            return;
        }

        DB::table('transactions')->where('id', $transaction->id)->update([
            'clean_description' => 'Visa Woolworths Sydney',
            'clean_description_source' => CleanDescriptionSource::Derived->value,
        ]);
    });

    $component->call('save')->assertHasNoErrors();

    $child = Transaction::query()->where('parent_transaction_id', $transaction->id)->sole();

    expect($reads)->toBeGreaterThanOrEqual(2)
        ->and($child->clean_description)->toBe('Visa Woolworths Sydney')
        ->and($child->clean_description_source)->toBe(CleanDescriptionSource::Derived);
});

test('a veto landing after the modal read a csv row never reaches the new version', function () {
    $transaction = Transaction::factory()->for($this->user)->for($this->account)->create([
        'source' => TransactionSource::Csv,
        'description' => 'VISA WOOLWORTHS 1234 SYDNEY',
        'clean_description' => 'Woolworths Group',
        'clean_description_source' => CleanDescriptionSource::Brand,
        'category_id' => null,
    ]);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('notes', 'Weekly shop');

    $reads = 0;
    Transaction::retrieved(function (Transaction $loaded) use (&$reads, $transaction): void {
        if ($loaded->id !== $transaction->id || ++$reads !== 2) {
            return;
        }

        DB::table('transactions')->where('id', $transaction->id)->update([
            'clean_description' => 'Visa Woolworths Sydney',
            'clean_description_source' => CleanDescriptionSource::Derived->value,
        ]);
    });

    $component->call('save')->assertHasNoErrors();

    $child = Transaction::query()->where('parent_transaction_id', $transaction->id)->sole();

    expect($reads)->toBeGreaterThanOrEqual(2)
        ->and($child->clean_description)->toBe('Visa Woolworths Sydney')
        ->and($child->clean_description_source)->toBe(CleanDescriptionSource::Derived);
});

function brandedTransferPair(User $user, Account $from, Account $to): array
{
    $name = [
        'description' => 'VISA WOOLWORTHS 1234 SYDNEY',
        'clean_description' => 'Woolworths Group',
        'clean_description_source' => CleanDescriptionSource::Brand,
        'category_id' => null,
        'amount' => 10000,
        'post_date' => '2026-03-15',
    ];

    $debit = Transaction::factory()->for($user)->create($name + [
        'account_id' => $from->id,
        'direction' => TransactionDirection::Debit,
        'source' => TransactionSource::Manual,
    ]);

    $credit = Transaction::factory()->for($user)->create($name + [
        'account_id' => $to->id,
        'direction' => TransactionDirection::Credit,
        'source' => TransactionSource::Manual,
        'transfer_pair_id' => $debit->id,
    ]);

    $debit->update(['transfer_pair_id' => $credit->id]);

    return [$debit, $credit];
}

function vetoBrandWhenTransactionBegins(Transaction ...$rows): void
{
    $ids = array_map(static fn (Transaction $row): int => $row->id, $rows);
    $fired = false;

    Event::listen(TransactionBeginning::class, function () use ($ids, &$fired): void {
        if ($fired) {
            return;
        }

        $fired = true;

        DB::table('transactions')->whereIn('id', $ids)->update([
            'clean_description' => 'Woolworths Sydney',
            'clean_description_source' => CleanDescriptionSource::Derived->value,
        ]);
    });
}

test('a veto landing after the modal read a transfer never reaches either new version', function () {
    $to = Account::factory()->for($this->user)->create();
    [$debit, $credit] = brandedTransferPair($this->user, $this->account, $to);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('notes', 'Monthly move');

    vetoBrandWhenTransactionBegins($debit, $credit);

    $component->call('save')->assertHasNoErrors();

    foreach ([$debit, $credit] as $parent) {
        $child = Transaction::query()->where('parent_transaction_id', $parent->id)->sole();

        expect($child->clean_description)->toBe('Woolworths Sydney')
            ->and($child->clean_description_source)->toBe(CleanDescriptionSource::Derived);
    }
});

test('a veto landing after the modal read a row converted to a transfer never reaches the new version', function () {
    $to = Account::factory()->for($this->user)->create();
    $transaction = adoptedRow($this->user, $this->account, [
        'description' => 'VISA WOOLWORTHS 1234 SYDNEY',
        'clean_description' => 'Woolworths Group',
        'clean_description_source' => CleanDescriptionSource::Brand,
    ]);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('transactionType', 'transfer')
        ->set('transferToAccountId', $to->id);

    vetoBrandWhenTransactionBegins($transaction);

    $component->call('save')->assertHasNoErrors();

    $child = Transaction::query()->where('parent_transaction_id', $transaction->id)->sole();

    expect($child->transfer_pair_id)->not->toBeNull()
        ->and($child->clean_description)->toBe('Woolworths Sydney')
        ->and($child->clean_description_source)->toBe(CleanDescriptionSource::Derived);
});

test('a veto landing after the modal read a transfer converted back never reaches the new version', function () {
    $to = Account::factory()->for($this->user)->create();
    [$debit, $credit] = brandedTransferPair($this->user, $this->account, $to);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('transactionType', 'expense');

    vetoBrandWhenTransactionBegins($debit, $credit);

    $component->call('save')->assertHasNoErrors();

    $child = Transaction::query()->where('parent_transaction_id', $debit->id)->sole();

    expect($child->transfer_pair_id)->toBeNull()
        ->and($child->clean_description)->toBe('Woolworths Sydney')
        ->and($child->clean_description_source)->toBe(CleanDescriptionSource::Derived);
});

test('the categorise-matching copy names a rename only when the rule will carry one', function () {
    $category = Category::factory()->create(['is_hidden' => false]);
    $imported = Transaction::factory()->for($this->user)->for($this->account)->fromRedbark()->create([
        'clean_description' => 'Woolworths',
        'clean_description_source' => CleanDescriptionSource::Feed,
        'category_id' => null,
    ]);
    $deliberate = Transaction::factory()->for($this->user)->for($this->account)->fromRedbark()->create([
        'clean_description' => 'Weekly groceries',
        'clean_description_source' => CleanDescriptionSource::Manual,
        'category_id' => null,
    ]);

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $imported->id)
        ->assertDontSee('renames them to')
        ->set('cleanDescription', 'Groceries')
        ->assertSee('renames them to');

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $deliberate->id)
        ->assertSee('renames them to');
});
