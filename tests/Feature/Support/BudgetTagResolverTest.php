<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BudgetTag;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\Payee;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserCategoryBudgetTag;
use App\Support\Budget\BudgetTagResolver;

function taggedRoot(?BudgetTag $tag, string $name = 'Groceries'): Category
{
    return Category::factory()->create(['name' => $name, 'budget_tag' => $tag]);
}

function transferLegs(User $user, Account $from, Account $to, int $amount = 5000): Transaction
{
    $transfer = Category::query()->firstOrCreate(['name' => 'Transfer'], ['is_hidden' => false]);

    $outgoing = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $from->id,
        'category_id' => $transfer->id,
        'direction' => TransactionDirection::Debit,
        'amount' => $amount,
    ]);
    $incoming = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $to->id,
        'category_id' => $transfer->id,
        'direction' => TransactionDirection::Credit,
        'amount' => $amount,
        'transfer_pair_id' => $outgoing->id,
    ]);
    $outgoing->update(['transfer_pair_id' => $incoming->id]);

    return $outgoing->refresh();
}

test('a payee override beats the user override, which beats the category default', function () {
    $user = User::factory()->create();
    $root = taggedRoot(BudgetTag::Needs);
    $resolver = new BudgetTagResolver;

    expect($resolver->forCategory($user, $root))->toBe(BudgetTag::Needs);

    UserCategoryBudgetTag::query()->create(['user_id' => $user->id, 'category_id' => $root->id, 'budget_tag' => BudgetTag::Wants]);

    expect($resolver->forCategory($user, $root))->toBe(BudgetTag::Wants);

    $payee = Payee::factory()->for($user)->create(['budget_tag' => BudgetTag::Savings]);

    expect($resolver->forCategory($user, $root, $payee))->toBe(BudgetTag::Savings);
});

test('a payee without an override falls through to the user override', function () {
    $user = User::factory()->create();
    $root = taggedRoot(BudgetTag::Needs);
    UserCategoryBudgetTag::query()->create(['user_id' => $user->id, 'category_id' => $root->id, 'budget_tag' => BudgetTag::Wants]);
    $payee = Payee::factory()->for($user)->create(['budget_tag' => null]);

    expect((new BudgetTagResolver)->forCategory($user, $root, $payee))->toBe(BudgetTag::Wants);
});

test('a child category inherits its root tag and the override on the root', function () {
    $user = User::factory()->create();
    $root = taggedRoot(BudgetTag::Wants, 'Eating Out');
    $child = Category::factory()->withParent($root)->create(['name' => 'Restaurant', 'budget_tag' => null]);
    $grandchild = Category::factory()->withParent($child)->create(['name' => 'Fine Dining', 'budget_tag' => null]);
    $resolver = new BudgetTagResolver;

    expect($resolver->forCategory($user, $child))->toBe(BudgetTag::Wants)
        ->and($resolver->forCategory($user, $grandchild))->toBe(BudgetTag::Wants)
        ->and($resolver->rootOf($grandchild)->is($root))->toBeTrue();

    UserCategoryBudgetTag::query()->create(['user_id' => $user->id, 'category_id' => $root->id, 'budget_tag' => BudgetTag::Needs]);

    expect($resolver->forCategory($user, $grandchild))->toBe(BudgetTag::Needs);
});

test('another user override never leaks', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $root = taggedRoot(BudgetTag::Needs);
    UserCategoryBudgetTag::query()->create(['user_id' => $other->id, 'category_id' => $root->id, 'budget_tag' => BudgetTag::Wants]);
    $resolver = new BudgetTagResolver;

    expect($resolver->forCategory($user, $root))->toBe(BudgetTag::Needs)
        ->and($resolver->forCategory($other, $root))->toBe(BudgetTag::Wants);
});

test('untagged roots and a missing category resolve to null', function () {
    $user = User::factory()->create();
    $income = taggedRoot(null, 'Income');
    $salary = Category::factory()->withParent($income)->create(['name' => 'Salary']);
    $resolver = new BudgetTagResolver;

    expect($resolver->forCategory($user, $income))->toBeNull()
        ->and($resolver->forCategory($user, $salary))->toBeNull()
        ->and($resolver->forCategory($user, null))->toBeNull();
});

test('a plain transaction takes the tag of its category', function () {
    $user = User::factory()->create();
    $root = taggedRoot(BudgetTag::Needs);
    $transaction = Transaction::factory()->create(['user_id' => $user->id, 'category_id' => $root->id]);

    expect((new BudgetTagResolver)->forTransaction($user, $transaction))->toBe(BudgetTag::Needs);
});

test('an income transaction has no tag', function () {
    $user = User::factory()->create();
    $income = taggedRoot(null, 'Income');
    $transaction = Transaction::factory()->credit()->create(['user_id' => $user->id, 'category_id' => $income->id]);

    expect((new BudgetTagResolver)->forTransaction($user, $transaction))->toBeNull();
});

test('a transfer from a tracked account to an untracked savings account is Savings', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $to = Account::factory()->for($user)->savings()->untracked()->create();
    $outgoing = transferLegs($user, $from, $to);

    expect((new BudgetTagResolver)->forTransaction($user, $outgoing))->toBe(BudgetTag::Savings);
});

test('only the debit leg of a tracked to untracked transfer is tagged', function () {
    $user = User::factory()->create();
    $tracked = Account::factory()->for($user)->create();
    $untracked = Account::factory()->for($user)->savings()->untracked()->create();
    $outgoing = transferLegs($user, $untracked, $tracked);

    expect((new BudgetTagResolver)->forTransaction($user, $outgoing))->toBeNull()
        ->and((new BudgetTagResolver)->forTransaction($user, $outgoing->transferPair))->toBeNull();
});

test('a transfer between two tracked accounts has no tag', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $to = Account::factory()->for($user)->savings()->create();
    $outgoing = transferLegs($user, $from, $to);

    expect((new BudgetTagResolver)->forTransaction($user, $outgoing))->toBeNull();
});

test('a transfer to an untracked account that is not savings or investment has no tag', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $to = Account::factory()->for($user)->untracked()->create();
    $outgoing = transferLegs($user, $from, $to);

    expect((new BudgetTagResolver)->forTransaction($user, $outgoing))->toBeNull();
});

test('a credit card repayment is Savings only when it leaves nothing owed', function (int $balanceAfter, ?BudgetTag $expected) {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $card = Account::factory()->for($user)->creditCard()->untracked()->create(['balance' => $balanceAfter]);
    $outgoing = transferLegs($user, $from, $card);

    expect((new BudgetTagResolver)->forTransaction($user, $outgoing))->toBe($expected);
})->with([
    'cleared' => [0, BudgetTag::Savings],
    'overpaid' => [2500, BudgetTag::Savings],
    'part paid' => [-40000, null],
]);

test('a repayment to a tracked credit card nets to zero and has no tag', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $card = Account::factory()->for($user)->creditCard()->create(['balance' => 0]);
    $outgoing = transferLegs($user, $from, $card);

    expect((new BudgetTagResolver)->forTransaction($user, $outgoing))->toBeNull();
});
