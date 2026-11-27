<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\BulkTransactionEdit;
use App\Enums\TransactionDirection;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionEmail;
use App\Models\User;
use App\Services\PendingMergeService;
use App\Services\TransactionFeeFolder;
use App\Services\Transactions\TransactionBulkEditor;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo('2026-09-20');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function relatedRow(object $test, array $overrides = []): Transaction
{
    $row = Transaction::factory()->manual()->create($overrides + [
        'user_id' => $test->user->id,
        'account_id' => $test->account->id,
        'amount' => -1000,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-09-10',
        'status' => TransactionStatus::Posted,
        'transfer_pair_id' => null,
        'planned_transaction_id' => null,
    ]);
    $row->splits()->createMany([
        ['category_id' => Category::factory()->for($test->user)->create()->id, 'amount' => -600, 'position' => 0],
        ['category_id' => Category::factory()->for($test->user)->create()->id, 'amount' => -400, 'position' => 1],
    ]);
    TransactionEmail::factory()->create(['user_id' => $test->user->id, 'transaction_id' => $row->id, 'gmail_message_id' => 'r-'.$row->id.'@mail']);

    return $row;
}

it('keeps linked emails through a bulk edit and still skips rows with category lines', function () {
    $plain = relatedRow($this);
    $plain->splits()->delete();
    $lined = relatedRow($this);

    $edited = app(TransactionBulkEditor::class)->edit(
        Transaction::query()->where('user_id', $this->user->id)->whereKey([$plain->id, $lined->id]),
        new BulkTransactionEdit(setNotes: true, notes: 'bulk'),
    );

    $current = Transaction::findCurrentVersion($plain->id, $this->user->id);

    expect($edited)->toBe(1)
        ->and($current->id)->not->toBe($plain->id)
        ->and($current->notes)->toBe('bulk')
        ->and($current->emails()->count())->toBe(1)
        ->and(Transaction::findCurrentVersion($lined->id, $this->user->id)->id)->toBe($lined->id);
});

it('keeps the surviving posted rows category lines and emails when a pending row is merged into it', function () {
    $posted = relatedRow($this);
    $pending = Transaction::factory()->manual()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'amount' => -1000,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-09-11',
        'status' => TransactionStatus::Pending,
        'transfer_pair_id' => null,
        'planned_transaction_id' => null,
    ]);

    $merged = app(PendingMergeService::class)->merge($this->user, $pending->id, $posted->id);

    expect($merged->splits()->count())->toBe(2)
        ->and($merged->emails()->pluck('gmail_message_id')->all())->toBe(['r-'.$posted->id.'@mail']);
});

it('keeps the purchases emails but not its category lines when a fee is folded into it', function () {
    $purchase = relatedRow($this, [
        'description' => 'VISA -JetBrains                Prague       CZ FRGN AMT-9.570000 051280 #8357',
        'amount' => -1000,
        'post_date' => CarbonImmutable::parse('2026-07-05'),
    ]);
    $fee = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Int Tran Fee - JetBrains              CZ - 951280',
        'amount' => -42,
        'direction' => TransactionDirection::Debit,
        'post_date' => CarbonImmutable::parse('2026-07-05'),
        'planned_transaction_id' => null,
        'transfer_pair_id' => null,
        'folded_into_transaction_id' => null,
    ]);

    $merged = app(TransactionFeeFolder::class)->fold($fee);

    expect($merged)->not->toBeNull()
        ->and($merged->amount)->toBe(-1042)
        ->and($merged->splits()->count())->toBe(0)
        ->and($merged->emails()->count())->toBe(1);
});
