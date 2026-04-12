<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Events\PlannedTransactionCategoryUpdated;
use App\Events\TransactionCategoryUpdated;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Event;

test('changing category on a linked transaction propagates to siblings', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $planned = PlannedTransaction::factory()->for($user)->for($account)->create();
    $oldCategory = Category::factory()->create();
    $newCategory = Category::factory()->create();

    $sibling1 = Transaction::factory()->for($user)->for($account)->create([
        'planned_transaction_id' => $planned->id,
        'category_id' => $oldCategory->id,
    ]);
    $sibling2 = Transaction::factory()->for($user)->for($account)->create([
        'planned_transaction_id' => $planned->id,
        'category_id' => $oldCategory->id,
    ]);
    $target = Transaction::factory()->for($user)->for($account)->create([
        'planned_transaction_id' => $planned->id,
        'category_id' => $oldCategory->id,
    ]);

    $target->update(['category_id' => $newCategory->id]);

    expect($sibling1->fresh()->category_id)->toBe($newCategory->id)
        ->and($sibling2->fresh()->category_id)->toBe($newCategory->id);
});

test('changing category on a linked transaction propagates to parent PlannedTransaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $oldCategory = Category::factory()->create();
    $newCategory = Category::factory()->create();
    $planned = PlannedTransaction::factory()->for($user)->for($account)->create([
        'category_id' => $oldCategory->id,
    ]);

    $transaction = Transaction::factory()->for($user)->for($account)->create([
        'planned_transaction_id' => $planned->id,
        'category_id' => $oldCategory->id,
    ]);

    $transaction->update(['category_id' => $newCategory->id]);

    expect($planned->fresh()->category_id)->toBe($newCategory->id);
});

test('changing category on a PlannedTransaction propagates to all linked transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $oldCategory = Category::factory()->create();
    $newCategory = Category::factory()->create();
    $planned = PlannedTransaction::factory()->for($user)->for($account)->create([
        'category_id' => $oldCategory->id,
    ]);

    $transaction1 = Transaction::factory()->for($user)->for($account)->create([
        'planned_transaction_id' => $planned->id,
        'category_id' => $oldCategory->id,
    ]);
    $transaction2 = Transaction::factory()->for($user)->for($account)->create([
        'planned_transaction_id' => $planned->id,
        'category_id' => $oldCategory->id,
    ]);

    $planned->update(['category_id' => $newCategory->id]);

    expect($transaction1->fresh()->category_id)->toBe($newCategory->id)
        ->and($transaction2->fresh()->category_id)->toBe($newCategory->id);
});

test('non-linked transactions do not propagate category changes', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $oldCategory = Category::factory()->create();
    $newCategory = Category::factory()->create();

    $otherTransaction = Transaction::factory()->for($user)->for($account)->create([
        'category_id' => $oldCategory->id,
    ]);

    $standalone = Transaction::factory()->for($user)->for($account)->create([
        'category_id' => $oldCategory->id,
    ]);

    $standalone->update(['category_id' => $newCategory->id]);

    expect($standalone->fresh()->category_id)->toBe($newCategory->id)
        ->and($otherTransaction->fresh()->category_id)->toBe($oldCategory->id);
});

test('no infinite loops when propagation updates happen', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $oldCategory = Category::factory()->create();
    $newCategory = Category::factory()->create();
    $planned = PlannedTransaction::factory()->for($user)->for($account)->create([
        'category_id' => $oldCategory->id,
    ]);

    Transaction::factory()->for($user)->for($account)->count(3)->create([
        'planned_transaction_id' => $planned->id,
        'category_id' => $oldCategory->id,
    ]);

    $eventCount = 0;
    Event::listen(TransactionCategoryUpdated::class, function () use (&$eventCount) {
        $eventCount++;
    });
    Event::listen(PlannedTransactionCategoryUpdated::class, function () use (&$eventCount) {
        $eventCount++;
    });

    $planned->update(['category_id' => $newCategory->id]);

    expect($eventCount)->toBe(1);
});

test('event only fires when category_id actually changed', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create();

    $transaction = Transaction::factory()->for($user)->for($account)->create([
        'category_id' => $category->id,
    ]);

    Event::fake([TransactionCategoryUpdated::class]);

    $transaction->update(['description' => 'updated description']);

    Event::assertNotDispatched(TransactionCategoryUpdated::class);
});

test('propagation does not affect transactions linked to a different PlannedTransaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $oldCategory = Category::factory()->create();
    $newCategory = Category::factory()->create();

    $planned1 = PlannedTransaction::factory()->for($user)->for($account)->create([
        'category_id' => $oldCategory->id,
    ]);
    $planned2 = PlannedTransaction::factory()->for($user)->for($account)->create([
        'category_id' => $oldCategory->id,
    ]);

    $transaction1 = Transaction::factory()->for($user)->for($account)->create([
        'planned_transaction_id' => $planned1->id,
        'category_id' => $oldCategory->id,
    ]);
    $unrelated = Transaction::factory()->for($user)->for($account)->create([
        'planned_transaction_id' => $planned2->id,
        'category_id' => $oldCategory->id,
    ]);

    $transaction1->update(['category_id' => $newCategory->id]);

    expect($unrelated->fresh()->category_id)->toBe($oldCategory->id)
        ->and($planned2->fresh()->category_id)->toBe($oldCategory->id);
});
