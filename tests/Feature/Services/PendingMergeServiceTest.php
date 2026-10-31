<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Enums\TransactionDirection;
use App\Enums\TransactionStatus;
use App\Exceptions\PendingMergeRefusedException;
use App\Models\Account;
use App\Models\AccountValuation;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\TransactionAttachment;
use App\Models\User;
use App\Services\PendingMergeService;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->travelTo('2026-09-20');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create(['balance' => 0, 'balance_source' => ImportSource::Manual]);
    $this->service = app(PendingMergeService::class);
});

function mergeRow(User $user, Account $account, int $cents, string $date, string $description, TransactionStatus $status, array $overrides = []): Transaction
{
    return Transaction::factory()->for($user)->for($account)->manual()->create([
        'amount' => $cents,
        'direction' => TransactionDirection::Debit,
        'post_date' => $date,
        'description' => $description,
        'status' => $status,
        ...$overrides,
    ]);
}

function mergeAttachments(Transaction $transaction, int $count): void
{
    TransactionAttachment::factory()->count($count)->create(['transaction_id' => $transaction->id]);
}

test('merges a pending transaction into its posted match', function () {
    $category = Category::factory()->for($this->user)->create(['is_hidden' => false]);
    $pending = mergeRow($this->user, $this->account, 5000, '2026-09-10', 'Corner Cafe Main', TransactionStatus::Pending, ['category_id' => $category->id]);
    $posted = mergeRow($this->user, $this->account, 6000, '2026-09-12', 'Corner Cafe Main St', TransactionStatus::Posted, ['merchant_name' => 'Corner Cafe']);

    $merged = $this->service->merge($this->user, $pending->id, $posted->id);

    $live = Transaction::query()->current()->where('account_id', $this->account->id)->get();

    expect($live)->toHaveCount(1)
        ->and($live->first()->id)->toBe($merged->id)
        ->and($merged->amount)->toBe(6000)
        ->and($merged->description)->toBe('Corner Cafe Main St')
        ->and($merged->merchant_name)->toBe('Corner Cafe')
        ->and($merged->post_date->toDateString())->toBe('2026-09-10')
        ->and($merged->category_id)->toBe($category->id)
        ->and(Transaction::query()->whereKey($pending->id)->exists())->toBeFalse();
});

test('keeps the posted category when the pending row has none', function () {
    $category = Category::factory()->for($this->user)->create(['is_hidden' => false]);
    $pending = mergeRow($this->user, $this->account, 5000, '2026-09-10', 'Corner Cafe Main', TransactionStatus::Pending);
    $posted = mergeRow($this->user, $this->account, 5000, '2026-09-11', 'Corner Cafe Main', TransactionStatus::Posted, ['category_id' => $category->id]);

    expect($this->service->merge($this->user, $pending->id, $posted->id)->category_id)->toBe($category->id);
});

test('joins notes and moves tags and attachments to the merged transaction', function () {
    $pending = mergeRow($this->user, $this->account, 5000, '2026-09-10', 'Corner Cafe Main', TransactionStatus::Pending, ['notes' => 'with Sam']);
    $posted = mergeRow($this->user, $this->account, 5000, '2026-09-11', 'Corner Cafe Main', TransactionStatus::Posted, ['notes' => 'team lunch']);
    $pendingTag = Tag::factory()->create(['user_id' => $this->user->id]);
    $postedTag = Tag::factory()->create(['user_id' => $this->user->id]);
    $pending->tags()->attach($pendingTag->id);
    $posted->tags()->attach($postedTag->id);
    mergeAttachments($pending, 2);
    mergeAttachments($posted, 3);

    $merged = $this->service->merge($this->user, $pending->id, $posted->id);

    expect($merged->notes)->toBe("with Sam\nteam lunch")
        ->and($merged->tags()->pluck('tags.id')->sort()->values()->all())->toBe(collect([$pendingTag->id, $postedTag->id])->sort()->values()->all())
        ->and($merged->attachments()->count())->toBe(5);
});

test('refuses a merge that would exceed ten attachments', function () {
    $pending = mergeRow($this->user, $this->account, 5000, '2026-09-10', 'Corner Cafe Main', TransactionStatus::Pending);
    $posted = mergeRow($this->user, $this->account, 5000, '2026-09-11', 'Corner Cafe Main', TransactionStatus::Posted);
    mergeAttachments($pending, 6);
    mergeAttachments($posted, 5);

    expect(fn () => $this->service->merge($this->user, $pending->id, $posted->id))->toThrow(PendingMergeRefusedException::class)
        ->and(Transaction::query()->whereKey($pending->id)->exists())->toBeTrue()
        ->and(Transaction::query()->current()->where('account_id', $this->account->id)->count())->toBe(2)
        ->and($pending->attachments()->count())->toBe(6)
        ->and($posted->attachments()->count())->toBe(5);
});

test('cannot merge into another users transaction', function () {
    $other = User::factory()->create();
    $theirs = mergeRow($other, Account::factory()->for($other)->create(), 5000, '2026-09-11', 'Corner Cafe Main', TransactionStatus::Posted);
    $pending = mergeRow($this->user, $this->account, 5000, '2026-09-10', 'Corner Cafe Main', TransactionStatus::Pending);

    expect(fn () => $this->service->merge($this->user, $pending->id, $theirs->id))->toThrow(PendingMergeRefusedException::class)
        ->and(Transaction::query()->whereKey($pending->id)->exists())->toBeTrue()
        ->and(Transaction::query()->where('parent_transaction_id', $theirs->id)->exists())->toBeFalse();
});

test('refuses rows that are not a pending and a posted row of one account', function () {
    $pending = mergeRow($this->user, $this->account, 5000, '2026-09-10', 'Corner Cafe Main', TransactionStatus::Pending);
    $posted = mergeRow($this->user, $this->account, 5000, '2026-09-11', 'Corner Cafe Main', TransactionStatus::Posted);
    $elsewhere = mergeRow($this->user, Account::factory()->for($this->user)->create(), 5000, '2026-09-11', 'Corner Cafe Main', TransactionStatus::Posted);

    expect(fn () => $this->service->merge($this->user, $posted->id, $pending->id))->toThrow(PendingMergeRefusedException::class)
        ->and(fn () => $this->service->merge($this->user, $pending->id, $elsewhere->id))->toThrow(PendingMergeRefusedException::class);
});

test('rebuilds the balance history from the earlier date', function () {
    AccountValuation::factory()->for($this->account)->opening()->create(['valued_on' => '2026-09-01', 'balance' => 100000]);
    $pending = mergeRow($this->user, $this->account, 5000, '2026-09-10', 'Corner Cafe Main', TransactionStatus::Pending);
    $posted = mergeRow($this->user, $this->account, 6000, '2026-09-12', 'Corner Cafe Main St', TransactionStatus::Posted);
    app(AccountBalanceHistoryBuilder::class)->rebuild($this->account);

    $this->service->merge($this->user, $pending->id, $posted->id);

    $end = fn (string $date): int => $this->account->dailyBalances()->whereDate('balance_date', $date)->sole()->end_balance;

    expect($end('2026-09-09'))->toBe(100000)
        ->and($end('2026-09-10'))->toBe(94000)
        ->and($end('2026-09-20'))->toBe(94000);
});

test('suggests the single posted row up to 25 percent larger within three days', function () {
    $pending = mergeRow($this->user, $this->account, 5000, '2026-09-10', 'Corner Cafe Main', TransactionStatus::Pending);
    $match = mergeRow($this->user, $this->account, 6000, '2026-09-12', 'Corner Cafe Main St', TransactionStatus::Posted);

    expect($this->service->suggestionFor($pending)?->id)->toBe($match->id);
});

test('suggests nothing when the posted row is too large, too late, named differently or ambiguous', function (int $cents, string $date, string $name) {
    $pending = mergeRow($this->user, $this->account, 5000, '2026-09-10', 'Corner Cafe Main', TransactionStatus::Pending);
    mergeRow($this->user, $this->account, $cents, $date, $name, TransactionStatus::Posted);

    expect($this->service->suggestionFor($pending))->toBeNull();
})->with([
    'more than 25 percent larger' => [6300, '2026-09-11', 'Corner Cafe Main St'],
    'smaller' => [4000, '2026-09-11', 'Corner Cafe Main St'],
    'four days later' => [5000, '2026-09-14', 'Corner Cafe Main St'],
    'different leading words' => [5000, '2026-09-11', 'Corner Bakery Main St'],
]);

test('suggests nothing when two posted rows qualify', function () {
    $pending = mergeRow($this->user, $this->account, 5000, '2026-09-10', 'Corner Cafe Main', TransactionStatus::Pending);
    mergeRow($this->user, $this->account, 5000, '2026-09-11', 'Corner Cafe Main St', TransactionStatus::Posted);
    mergeRow($this->user, $this->account, 5500, '2026-09-12', 'Corner Cafe Main Rd', TransactionStatus::Posted);

    expect($this->service->suggestionFor($pending))->toBeNull();
});

test('lists posted candidates ten at a time, newest first, of the same user and account only', function () {
    $pending = mergeRow($this->user, $this->account, 5000, '2026-09-10', 'Corner Cafe Main', TransactionStatus::Pending);

    foreach (range(1, 25) as $day) {
        mergeRow($this->user, $this->account, 1000 + $day, '2026-08-'.mb_str_pad((string) $day, 2, '0', STR_PAD_LEFT), 'Row '.$day, TransactionStatus::Posted);
    }

    mergeRow($this->user, Account::factory()->for($this->user)->create(), 5000, '2026-09-10', 'Other account', TransactionStatus::Posted);
    $stranger = User::factory()->create();
    mergeRow($stranger, Account::factory()->for($stranger)->create(), 5000, '2026-09-10', 'Other user', TransactionStatus::Posted);

    $first = $this->service->candidatesFor($pending);
    $more = $this->service->candidatesFor($pending, 20);

    expect($first)->toHaveCount(10)
        ->and($first->first()->description)->toBe('Row 25')
        ->and($more)->toHaveCount(20)
        ->and($more->pluck('account_id')->unique()->all())->toBe([$this->account->id]);
});

test('merging an edited pending row retires every earlier version of it', function () {
    $original = mergeRow($this->user, $this->account, 5000, '2026-09-10', 'Corner Cafe Main', TransactionStatus::Pending, ['redbark_id' => 'rb_pending']);
    $edited = $original->createChild(['notes' => 'edited']);
    $posted = mergeRow($this->user, $this->account, 5000, '2026-09-11', 'Corner Cafe Main St', TransactionStatus::Posted);

    $child = $this->service->merge($this->user, $edited->id, $posted->id);

    $retired = Transaction::withTrashed()->whereIn('id', [$original->id, $edited->id])->get();

    expect(Transaction::query()->where('user_id', $this->user->id)->current()->pluck('id')->all())->toBe([$child->id])
        ->and($retired->every(fn (Transaction $row) => $row->trashed() && $row->folded_into_transaction_id === $child->id))->toBeTrue();
});

test('the merged row locks the fields it took from the pending row', function () {
    $pending = mergeRow($this->user, $this->account, 5000, '2026-09-10', 'Corner Cafe Main', TransactionStatus::Pending);
    $posted = mergeRow($this->user, $this->account, 5000, '2026-09-12', 'Corner Cafe Main St', TransactionStatus::Posted);

    $child = $this->service->merge($this->user, $pending->id, $posted->id);

    expect($child->locked_fields)->toContain('post_date');
});

test('keeps the receipts of an earlier pending version and their files when the row is merged', function () {
    Storage::fake('local');
    $original = mergeRow($this->user, $this->account, 5000, '2026-09-10', 'Corner Cafe Main', TransactionStatus::Pending);
    $edited = $original->createChild(['notes' => 'edited']);
    $posted = mergeRow($this->user, $this->account, 5000, '2026-09-11', 'Corner Cafe Main St', TransactionStatus::Posted);
    $path = 'transaction-attachments/'.$original->id.'/late.pdf';
    Storage::disk('local')->put($path, 'receipt');
    TransactionAttachment::factory()->create(['transaction_id' => $original->id, 'path' => $path]);

    $child = $this->service->merge($this->user, $edited->id, $posted->id);

    expect($child->attachments()->pluck('path')->all())->toBe([$path])
        ->and(Storage::disk('local')->exists($path))->toBeTrue()
        ->and(TransactionAttachment::query()->whereIn('transaction_id', [$original->id, $edited->id])->count())->toBe(0);
});

test('carries the pending row planned transaction link onto the merged row', function () {
    $planned = PlannedTransaction::factory()->for($this->user)->create();
    $pending = mergeRow($this->user, $this->account, 5000, '2026-09-10', 'Corner Cafe Main', TransactionStatus::Pending, ['planned_transaction_id' => $planned->id]);
    $posted = mergeRow($this->user, $this->account, 5000, '2026-09-11', 'Corner Cafe Main', TransactionStatus::Posted);

    expect($this->service->merge($this->user, $pending->id, $posted->id)->planned_transaction_id)->toBe($planned->id);
});

test('keeps the posted row planned transaction link over the pending one', function () {
    [$posts, $holds] = PlannedTransaction::factory()->count(2)->for($this->user)->create();
    $pending = mergeRow($this->user, $this->account, 5000, '2026-09-10', 'Corner Cafe Main', TransactionStatus::Pending, ['planned_transaction_id' => $holds->id]);
    $posted = mergeRow($this->user, $this->account, 5000, '2026-09-11', 'Corner Cafe Main', TransactionStatus::Posted, ['planned_transaction_id' => $posts->id]);

    expect($this->service->merge($this->user, $pending->id, $posted->id)->planned_transaction_id)->toBe($posts->id);
});

test('a transfer suggestion on the posted row follows the merged row', function () {
    $otherAccount = Account::factory()->for($this->user)->create(['balance' => 0, 'balance_source' => ImportSource::Manual]);
    $pending = mergeRow($this->user, $this->account, 5000, '2026-09-11', 'Corner Cafe Main', TransactionStatus::Pending);
    $posted = mergeRow($this->user, $this->account, 5000, '2026-09-11', 'Corner Cafe Main', TransactionStatus::Posted);
    $partner = mergeRow($this->user, $otherAccount, 5000, '2026-09-11', 'Transfer in', TransactionStatus::Posted, ['direction' => TransactionDirection::Credit]);
    $posted->forceFill(['suggested_pair_id' => $partner->id])->save();
    $partner->forceFill(['suggested_pair_id' => $posted->id])->save();

    $merged = $this->service->merge($this->user, $pending->id, $posted->id);

    expect($partner->fresh()->suggested_pair_id)->toBe($merged->id);
});

test('a transfer suggestion is dropped on both sides when the merge moves the date it was matched on', function () {
    $otherAccount = Account::factory()->for($this->user)->create(['balance' => 0, 'balance_source' => ImportSource::Manual]);
    $pending = mergeRow($this->user, $this->account, 5000, '2026-09-10', 'Corner Cafe Main', TransactionStatus::Pending);
    $posted = mergeRow($this->user, $this->account, 5000, '2026-09-11', 'Corner Cafe Main', TransactionStatus::Posted);
    $partner = mergeRow($this->user, $otherAccount, 5000, '2026-09-11', 'Transfer in', TransactionStatus::Posted, ['direction' => TransactionDirection::Credit]);
    $posted->forceFill(['suggested_pair_id' => $partner->id])->save();
    $partner->forceFill(['suggested_pair_id' => $posted->id])->save();

    $merged = $this->service->merge($this->user, $pending->id, $posted->id);

    expect($partner->fresh()->suggested_pair_id)->toBeNull()
        ->and($merged->suggested_pair_id)->toBeNull();
});
