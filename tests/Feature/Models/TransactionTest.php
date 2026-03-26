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

test('deleting transfer pair nullifies transfer_pair_id', function () {
    $pair = Transaction::factory()->create();
    $transaction = Transaction::factory()->create(['transfer_pair_id' => $pair->id]);

    $pair->delete();

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
