<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('factory creates a valid transaction', function () {
    $transaction = Transaction::factory()->create();

    expect($transaction)->toBeInstanceOf(Transaction::class)
        ->and($transaction->exists)->toBeTrue();
});

test('default factory creates a debit transaction', function () {
    $transaction = Transaction::factory()->create();

    expect($transaction->direction)->toBe(TransactionDirection::Debit);
});

test('default factory creates a posted transaction', function () {
    $transaction = Transaction::factory()->create();

    expect($transaction->status)->toBe(TransactionStatus::Posted);
});

test('credit state produces credit direction', function () {
    $transaction = Transaction::factory()->credit()->create();

    expect($transaction->direction)->toBe(TransactionDirection::Credit);
});

test('pending state produces pending status', function () {
    $transaction = Transaction::factory()->pending()->create();

    expect($transaction->status)->toBe(TransactionStatus::Pending);
});

test('withCategory state assigns a category', function () {
    $transaction = Transaction::factory()->withCategory()->create();

    expect($transaction->category)->toBeInstanceOf(Category::class);
});

test('fromBasiq state populates basiq fields', function () {
    $transaction = Transaction::factory()->fromBasiq()->create();

    expect($transaction->basiq_id)->not->toBeNull()
        ->and($transaction->basiq_account_id)->not->toBeNull()
        ->and($transaction->merchant_name)->not->toBeNull()
        ->and($transaction->anzsic_code)->not->toBeNull()
        ->and($transaction->enrich_data)->toBeArray();
});

test('category_id is nullable', function () {
    $transaction = Transaction::factory()->create();

    expect($transaction->category_id)->toBeNull();
});

test('basiq_id must be unique', function () {
    $basiqId = 'unique-basiq-id';
    Transaction::factory()->create(['basiq_id' => $basiqId]);

    expect(fn () => Transaction::factory()->create(['basiq_id' => $basiqId]))
        ->toThrow(QueryException::class);
});

test('transaction belongs to a user', function () {
    $transaction = Transaction::factory()->create();

    expect($transaction->user)->toBeInstanceOf(User::class);
});

test('transaction belongs to an account', function () {
    $transaction = Transaction::factory()->create();

    expect($transaction->account)->toBeInstanceOf(Account::class);
});

test('transaction belongs to a category', function () {
    $transaction = Transaction::factory()->withCategory()->create();

    expect($transaction->category)->toBeInstanceOf(Category::class);
});

test('user has many transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Transaction::factory()->count(3)->for($user)->for($account)->create();

    expect($user->transactions)->toHaveCount(3)
        ->each(fn (Pest\Expectation $transaction) => $transaction->toBeInstanceOf(Transaction::class));
});

test('account has many transactions', function () {
    $account = Account::factory()->create();
    Transaction::factory()->count(3)->for($account->user)->for($account)->create();

    expect($account->transactions)->toHaveCount(3)
        ->each(fn (Pest\Expectation $transaction) => $transaction->toBeInstanceOf(Transaction::class));
});

test('deleting a user cascades to transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Transaction::factory()->count(2)->for($user)->for($account)->create();

    $user->delete();

    expect(Transaction::where('user_id', $user->id)->count())->toBe(0);
});

test('deleting an account cascades to transactions', function () {
    $account = Account::factory()->create();
    Transaction::factory()->count(2)->for($account->user)->for($account)->create();

    $account->delete();

    expect(Transaction::where('account_id', $account->id)->count())->toBe(0);
});

test('deleting a category nullifies transaction category_id', function () {
    $category = Category::factory()->create();
    $transaction = Transaction::factory()->create(['category_id' => $category->id]);

    $category->delete();

    expect($transaction->fresh()->category_id)->toBeNull();
});

test('direction is cast to TransactionDirection enum', function () {
    $transaction = Transaction::factory()->create();

    expect($transaction->direction)->toBeInstanceOf(TransactionDirection::class);
});

test('status is cast to TransactionStatus enum', function () {
    $transaction = Transaction::factory()->create();

    expect($transaction->status)->toBeInstanceOf(TransactionStatus::class);
});

test('post_date is cast to date', function () {
    $transaction = Transaction::factory()->create(['post_date' => '2026-03-14']);

    expect($transaction->post_date)
        ->toBeInstanceOf(Carbon\CarbonImmutable::class)
        ->and($transaction->post_date->toDateString())->toBe('2026-03-14');
});

test('enrich_data is cast to array', function () {
    $data = ['merchant' => ['name' => 'Test']];
    $transaction = Transaction::factory()->create(['enrich_data' => $data]);

    expect($transaction->enrich_data)->toBeArray()
        ->and($transaction->enrich_data['merchant']['name'])->toBe('Test');
});

test('amount is stored as integer cents', function () {
    $transaction = Transaction::factory()->create(['amount' => 4599]);

    expect($transaction->amount)->toBe(4599)
        ->and($transaction->amount)->toBeInt();
});

test('source defaults to manual for new transactions', function () {
    $attributes = Transaction::factory()->make()->getAttributes();
    unset($attributes['source']);

    $id = DB::table('transactions')->insertGetId($attributes);
    $transaction = Transaction::query()->findOrFail($id);

    expect($transaction->source)->toBe(TransactionSource::Manual);
});

test('source is cast to TransactionSource enum', function () {
    $transaction = Transaction::factory()->create();

    expect($transaction->source)->toBeInstanceOf(TransactionSource::class);
});

test('fromBasiq factory state sets source to basiq', function () {
    $transaction = Transaction::factory()->fromBasiq()->create();

    expect($transaction->source)->toBe(TransactionSource::Basiq);
});

test('manual factory state sets source to manual', function () {
    $transaction = Transaction::factory()->manual()->create();

    expect($transaction->source)->toBe(TransactionSource::Manual);
});

test('transfer factory state links transfer pair', function () {
    $transaction = Transaction::factory()->transfer()->create();

    expect($transaction->transfer_pair_id)->not->toBeNull();
});

test('transfer pair relationship returns a transaction', function () {
    $pair = Transaction::factory()->create();
    $transaction = Transaction::factory()->create(['transfer_pair_id' => $pair->id]);

    expect($transaction->transferPair)->toBeInstanceOf(Transaction::class)
        ->and($transaction->transferPair->id)->toBe($pair->id);
});

test('soft-deleting transfer pair preserves transfer_pair_id', function () {
    $pair = Transaction::factory()->create();
    $transaction = Transaction::factory()->create(['transfer_pair_id' => $pair->id]);

    $pair->delete();

    expect($transaction->fresh()->transfer_pair_id)->toBe($pair->id);
});

test('force-deleting transfer pair nullifies transfer_pair_id', function () {
    $pair = Transaction::factory()->create();
    $transaction = Transaction::factory()->create(['transfer_pair_id' => $pair->id]);

    $pair->forceDelete();

    expect($transaction->fresh()->transfer_pair_id)->toBeNull();
});

test('planned_transaction_id is nullable', function () {
    $transaction = Transaction::factory()->create();

    expect($transaction->planned_transaction_id)->toBeNull();
});

test('notes column is nullable', function () {
    $transaction = Transaction::factory()->create();

    expect($transaction->notes)->toBeNull();
});

test('withNotes factory state populates notes', function () {
    $transaction = Transaction::factory()->withNotes()->create();

    expect($transaction->notes)->not->toBeNull()
        ->and($transaction->notes)->toBeString();
});

// ── Parent-Child Architecture (#134) ─────────────────────────────

test('parent relationship returns parent transaction', function () {
    $parent = Transaction::factory()->create();
    $child = Transaction::factory()->create(['parent_transaction_id' => $parent->id]);

    expect($child->parent)->toBeInstanceOf(Transaction::class)
        ->and($child->parent->id)->toBe($parent->id);
});

test('children relationship returns child transactions', function () {
    $parent = Transaction::factory()->create();
    Transaction::factory()->count(2)->create(['parent_transaction_id' => $parent->id]);

    expect($parent->children)->toHaveCount(2)
        ->each(fn (Pest\Expectation $child) => $child->toBeInstanceOf(Transaction::class));
});

test('createChild copies all fields and sets parent_transaction_id', function () {
    $parent = Transaction::factory()->withCategory()->withNotes()->create([
        'amount' => 5000,
        'description' => 'original coffee',
        'post_date' => '2026-03-15',
    ]);

    $child = $parent->createChild();

    expect($child->parent_transaction_id)->toBe($parent->id)
        ->and($child->user_id)->toBe($parent->user_id)
        ->and($child->account_id)->toBe($parent->account_id)
        ->and($child->category_id)->toBe($parent->category_id)
        ->and($child->amount)->toBe($parent->amount)
        ->and($child->direction)->toBe($parent->direction)
        ->and($child->description)->toBe($parent->description)
        ->and($child->post_date->format('Y-m-d'))->toBe('2026-03-15')
        ->and($child->notes)->toBe($parent->notes)
        ->and($child->id)->not->toBe($parent->id);
});

test('createChild applies overrides on top of copied fields', function () {
    $parent = Transaction::factory()->create([
        'amount' => 5000,
        'description' => 'original',
    ]);

    $child = $parent->createChild([
        'amount' => 9999,
        'description' => 'updated',
    ]);

    expect($child->amount)->toBe(9999)
        ->and($child->description)->toBe('updated')
        ->and($child->account_id)->toBe($parent->account_id);
});

test('scopeCurrent excludes transactions with live children', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $parent = Transaction::factory()->for($user)->for($account)->create();
    Transaction::factory()->for($user)->for($account)->create([
        'parent_transaction_id' => $parent->id,
    ]);

    $current = Transaction::query()
        ->where('user_id', $user->id)
        ->current()
        ->pluck('id');

    expect($current)->not->toContain($parent->id);
});

test('scopeCurrent includes transactions with no children', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $standalone = Transaction::factory()->for($user)->for($account)->create();

    $current = Transaction::query()
        ->where('user_id', $user->id)
        ->current()
        ->pluck('id');

    expect($current)->toContain($standalone->id);
});

test('scopeCurrent includes parent whose child is soft-deleted', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $parent = Transaction::factory()->for($user)->for($account)->create();
    $child = Transaction::factory()->for($user)->for($account)->create([
        'parent_transaction_id' => $parent->id,
    ]);

    $child->delete();

    $current = Transaction::query()
        ->where('user_id', $user->id)
        ->current()
        ->pluck('id');

    expect($current)->toContain($parent->id);
});

test('soft delete sets deleted_at and excludes from default queries', function () {
    $transaction = Transaction::factory()->create();

    $transaction->delete();

    expect(Transaction::query()->find($transaction->id))->toBeNull()
        ->and(Transaction::withTrashed()->find($transaction->id))->not->toBeNull()
        ->and(Transaction::withTrashed()->find($transaction->id)->deleted_at)->not->toBeNull();
});

test('findCurrentVersion returns child when parent has one', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $parent = Transaction::factory()->for($user)->for($account)->create();
    $child = Transaction::factory()->for($user)->for($account)->create([
        'parent_transaction_id' => $parent->id,
    ]);

    $found = Transaction::findCurrentVersion($parent->id, $user->id);

    expect($found->id)->toBe($child->id);
});

test('findCurrentVersion returns self when no children', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $standalone = Transaction::factory()->for($user)->for($account)->create();

    $found = Transaction::findCurrentVersion($standalone->id, $user->id);

    expect($found->id)->toBe($standalone->id);
});

test('findCurrentVersion walks full chain from original ancestor', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $grandparent = Transaction::factory()->for($user)->for($account)->create();
    $parent = Transaction::factory()->for($user)->for($account)->create([
        'parent_transaction_id' => $grandparent->id,
    ]);
    $child = $parent->createChild(['description' => 'grandchild']);

    $found = Transaction::findCurrentVersion($grandparent->id, $user->id);

    expect($found->id)->toBe($child->id);
});

test('createChild does not copy basiq_id from parent', function () {
    $parent = Transaction::factory()->fromBasiq()->create();

    $child = $parent->createChild();

    expect($child->basiq_id)->toBeNull()
        ->and($parent->basiq_id)->not->toBeNull();
});

test('findCurrentVersion walks chain from middle node', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $grandparent = Transaction::factory()->for($user)->for($account)->create();
    $parent = Transaction::factory()->for($user)->for($account)->create([
        'parent_transaction_id' => $grandparent->id,
    ]);
    $child = $parent->createChild(['description' => 'grandchild']);

    $found = Transaction::findCurrentVersion($parent->id, $user->id);

    expect($found->id)->toBe($child->id);
});

test('withParent factory state sets parent_transaction_id', function () {
    $transaction = Transaction::factory()->withParent()->create();

    expect($transaction->parent_transaction_id)->not->toBeNull()
        ->and($transaction->parent)->toBeInstanceOf(Transaction::class);
});

test('softDeleted factory state sets deleted_at', function () {
    $transaction = Transaction::factory()->softDeleted()->create();

    expect(Transaction::query()->find($transaction->id))->toBeNull()
        ->and(Transaction::withTrashed()->find($transaction->id)->deleted_at)->not->toBeNull();
});

test('deleting parent with nullOnDelete sets child parent_transaction_id to null', function () {
    $parent = Transaction::factory()->create();
    $child = Transaction::factory()->create(['parent_transaction_id' => $parent->id]);

    $parent->forceDelete();

    expect($child->fresh()->parent_transaction_id)->toBeNull();
});

test('createChild preserves planned_transaction_id from parent', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $planned = App\Models\PlannedTransaction::factory()->for($user)->for($account)->create();

    $parent = Transaction::factory()->for($user)->for($account)->create([
        'planned_transaction_id' => $planned->id,
    ]);

    $child = $parent->createChild();

    expect($child->planned_transaction_id)->toBe($planned->id);
});

test('backfill sets source to basiq for transactions with basiq_id', function () {
    $basiqTransaction = Transaction::factory()->create([
        'basiq_id' => 'test-basiq-id',
        'source' => 'manual',
    ]);
    $manualTransaction = Transaction::factory()->create([
        'basiq_id' => null,
        'source' => 'manual',
    ]);

    DB::table('transactions')
        ->whereNotNull('basiq_id')
        ->update(['source' => 'basiq']);

    expect($basiqTransaction->fresh()->source)->toBe(TransactionSource::Basiq)
        ->and($manualTransaction->fresh()->source)->toBe(TransactionSource::Manual);
});
