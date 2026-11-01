<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\CleanDescriptionSource;
use App\Enums\ImportSource;
use App\Enums\TransactionDirection;
use App\Enums\TransferLinkSource;
use App\Livewire\TransactionList;
use App\Models\Account;
use App\Models\AccountValuation;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo('2026-06-15');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function bulkRow(User $user, Account $account, array $overrides = []): Transaction
{
    return Transaction::factory()->for($user)->for($account)->manual()->create(array_merge([
        'post_date' => '2026-06-10',
        'amount' => 1000,
        'direction' => TransactionDirection::Debit,
        'description' => 'CAFE '.fake()->unique()->numerify('####'),
        'transfer_pair_id' => null,
    ], $overrides));
}

function bulkFeedRow(User $user, Account $account, array $overrides = []): Transaction
{
    return Transaction::factory()->for($user)->for($account)->fromRedbark()->create(array_merge([
        'post_date' => '2026-06-10',
        'amount' => 1000,
        'direction' => TransactionDirection::Debit,
        'description' => 'FEED '.fake()->unique()->numerify('####'),
        'transfer_pair_id' => null,
    ], $overrides));
}

/** @return array{Transaction, Transaction} the manual debit leg and its credit leg */
function bulkTransferPair(User $user, Account $from, Account $to, bool $bankFeedCredit = false): array
{
    $debit = bulkRow($user, $from, ['description' => 'TRANSFER OUT']);
    $credit = ($bankFeedCredit
        ? Transaction::factory()->for($user)->for($to)->fromRedbark()
        : Transaction::factory()->for($user)->for($to)->manual())
        ->create([
            'post_date' => '2026-06-10',
            'amount' => 1000,
            'direction' => TransactionDirection::Credit,
            'description' => 'TRANSFER IN',
            'transfer_pair_id' => $debit->id,
        ]);
    $debit->update(['transfer_pair_id' => $credit->id]);

    return [$debit, $credit];
}

/** @param  iterable<Transaction>  $rows */
function bulkSelection(iterable $rows): array
{
    $selected = [];

    foreach ($rows as $row) {
        $selected[$row->id] = true;
    }

    return $selected;
}

function bulkList(User $user, iterable $rows): Testable
{
    return Livewire::actingAs($user)
        ->test(TransactionList::class)
        ->set('selected', bulkSelection($rows));
}

function bulkHistoryAccount(User $user, int $opening = 100000): Account
{
    $account = Account::factory()->for($user)->create(['balance' => 0, 'balance_source' => ImportSource::Manual]);
    AccountValuation::factory()->for($account)->opening()->create(['valued_on' => '2026-06-01', 'balance' => $opening]);
    app(AccountBalanceHistoryBuilder::class)->rebuild($account);

    return $account;
}

function bulkHistoryRow(User $user, Account $account, string $date, int $amount): Transaction
{
    $row = bulkRow($user, $account, ['post_date' => $date, 'amount' => $amount]);
    app(AccountBalanceHistoryBuilder::class)->rebuild($account);

    return $row;
}

function currentVersionOf(Transaction $row): Transaction
{
    $current = Transaction::findCurrentVersion($row->id, $row->user_id);

    expect($current)->toBeInstanceOf(Transaction::class);

    return $current;
}

function outermostTransactionsDuring(Closure $callback): int
{
    $outermostLevel = DB::transactionLevel() + 1;
    $begun = 0;

    Event::listen(TransactionBeginning::class, function () use (&$begun, $outermostLevel): void {
        if (DB::transactionLevel() === $outermostLevel) {
            $begun++;
        }
    });

    $callback();

    return $begun;
}

it('bulk delete of both edited transfer legs leaves the restored originals unlinked', function () {
    $other = Account::factory()->for($this->user)->create();
    [$debit, $credit] = bulkTransferPair($this->user, $this->account, $other);

    bulkList($this->user, [$debit, $credit])
        ->set('bulkEditInclude', ['notes'])
        ->set('bulkEditNotes', 'edited')
        ->call('bulkEdit');

    bulkList($this->user, [currentVersionOf($debit), currentVersionOf($credit)])->call('bulkDelete');

    $restored = Transaction::query()->whereIn('id', [$debit->id, $credit->id])->get();

    expect($restored)->toHaveCount(2)
        ->and($restored->pluck('transfer_pair_id')->filter()->all())->toBe([]);
});

it('bulk delete skips original bank feed rows', function () {
    $manual = collect(range(1, 5))->map(fn (): Transaction => bulkRow($this->user, $this->account));
    $feed = collect(range(1, 2))->map(fn (): Transaction => bulkFeedRow($this->user, $this->account));

    $component = bulkList($this->user, $manual->merge($feed))->call('bulkDelete');

    $manual->each(fn (Transaction $row) => expect(Transaction::query()->find($row->id))->toBeNull());
    $feed->each(fn (Transaction $row) => expect(Transaction::query()->find($row->id))->not->toBeNull());

    expect($component->get('bulkNotice'))->toBe('Deleted 5 transactions. Skipped 2 that cannot be deleted.')
        ->and($component->get('selected'))->toBe([]);
});

it('bulk delete unlinks a bank feed transfer partner', function () {
    $other = Account::factory()->for($this->user)->create();
    [$manualLeg, $feedLeg] = bulkTransferPair($this->user, $this->account, $other, bankFeedCredit: true);

    bulkList($this->user, [$manualLeg])->call('bulkDelete');

    $partner = Transaction::query()->find($feedLeg->id);

    expect(Transaction::query()->find($manualLeg->id))->toBeNull()
        ->and($partner)->not->toBeNull()
        ->and($partner->transfer_pair_id)->toBeNull()
        ->and($partner->transfer_link_source)->toBe(TransferLinkSource::Unlinked);
});

it('bulk delete keeps a tracked manual transfer partner as an ordinary transaction', function () {
    $other = Account::factory()->for($this->user)->create();
    [$debit, $credit] = bulkTransferPair($this->user, $this->account, $other);

    bulkList($this->user, [$debit])->call('bulkDelete');

    $partner = Transaction::query()->find($credit->id);

    expect(Transaction::query()->find($debit->id))->toBeNull()
        ->and($partner)->not->toBeNull()
        ->and($partner->transfer_pair_id)->toBeNull();
});

it('bulk delete removes both legs of a manual transfer selected together', function () {
    $other = Account::factory()->for($this->user)->create();
    [$debit, $credit] = bulkTransferPair($this->user, $this->account, $other);

    $component = bulkList($this->user, [$debit, $credit])->call('bulkDelete');

    expect(Transaction::query()->whereIn('id', [$debit->id, $credit->id])->count())->toBe(0)
        ->and($component->get('bulkNotice'))->toBe('Deleted 2 transactions.');
});

it('bulk delete ignores another users transactions', function () {
    $intruder = User::factory()->create();
    $theirs = bulkRow($intruder, Account::factory()->for($intruder)->create());
    $mine = bulkRow($this->user, $this->account);

    $component = bulkList($this->user, [$mine, $theirs])->call('bulkDelete');

    expect(Transaction::query()->find($mine->id))->toBeNull()
        ->and(Transaction::query()->find($theirs->id))->not->toBeNull()
        ->and($component->get('bulkNotice'))->toBe('Deleted 1 transaction. Skipped 1 that cannot be deleted.');
});

it('bulk delete rebuilds balance history in the same transaction as the delete', function () {
    $account = bulkHistoryAccount($this->user);
    $first = bulkHistoryRow($this->user, $account, '2026-06-05', 2000);
    $second = bulkHistoryRow($this->user, $account, '2026-06-08', 3000);
    $kept = bulkHistoryRow($this->user, $account, '2026-06-09', 500);

    $outermost = outermostTransactionsDuring(fn () => bulkList($this->user, [$first, $second])->call('bulkDelete'));

    expect($outermost)->toBe(1)
        ->and($account->dailyBalances()->whereDate('balance_date', '2026-06-15')->sole()->end_balance)->toBe(99500)
        ->and(Transaction::query()->find($kept->id))->not->toBeNull();

    expectBalanceHistoryConsistent($account);
});

it('bulk delete of a transfer leg keeps both accounts balance history consistent', function () {
    $from = bulkHistoryAccount($this->user);
    $to = bulkHistoryAccount($this->user, 50000);
    [$debit, $credit] = bulkTransferPair($this->user, $from, $to);
    app(AccountBalanceHistoryBuilder::class)->rebuild($from);
    app(AccountBalanceHistoryBuilder::class)->rebuild($to);

    bulkList($this->user, [$debit])->call('bulkDelete');

    expect($to->dailyBalances()->whereDate('balance_date', '2026-06-15')->sole()->end_balance)->toBe(51000);

    expectBalanceHistoryConsistent($from, $to);
});

it('bulk edit changes only the fields included', function () {
    $rows = collect(range(1, 10))->map(fn (int $i): Transaction => bulkRow($this->user, $this->account, [
        'description' => "ORIGINAL {$i}",
        'post_date' => '2026-06-0'.(($i % 9) + 1),
        'notes' => null,
    ]));

    $component = bulkList($this->user, $rows)
        ->set('bulkEditInclude', ['notes'])
        ->set('bulkEditNotes', 'checked with the bank')
        ->set('bulkEditName', 'SHOULD BE IGNORED')
        ->set('bulkEditDate', '2026-06-14')
        ->call('bulkEdit')
        ->assertHasNoErrors();

    $rows->each(function (Transaction $row): void {
        $current = currentVersionOf($row);

        expect($current->id)->not->toBe($row->id)
            ->and($current->parent_transaction_id)->toBe($row->id)
            ->and($current->notes)->toBe('checked with the bank')
            ->and($current->description)->toBe($row->description)
            ->and($current->post_date->toDateString())->toBe($row->post_date->toDateString());
    });

    expect($component->get('bulkNotice'))->toBe('Edited 10 transactions.');
});

it('bulk edit changes the name and date of a manual row and keeps its display name in step', function () {
    $row = bulkRow($this->user, $this->account, [
        'description' => 'COFFEE',
        'clean_description' => 'COFFEE',
        'clean_description_source' => CleanDescriptionSource::Manual,
        'notes' => 'keep this note',
    ]);

    bulkList($this->user, [$row])
        ->set('bulkEditInclude', ['name', 'date'])
        ->set('bulkEditName', 'Morning coffee')
        ->set('bulkEditDate', '2026-06-12')
        ->call('bulkEdit')
        ->assertHasNoErrors();

    $current = currentVersionOf($row);

    expect($current->description)->toBe('Morning coffee')
        ->and($current->clean_description)->toBe('Morning coffee')
        ->and($current->post_date->toDateString())->toBe('2026-06-12')
        ->and($current->locked_fields)->toContain('description', 'post_date')
        ->and($current->notes)->toBe('keep this note');
});

it('bulk edit clears notes only when notes are included and empty', function () {
    $row = bulkRow($this->user, $this->account, ['notes' => 'old note', 'description' => 'KEEP ME']);

    bulkList($this->user, [$row])
        ->set('bulkEditInclude', ['notes'])
        ->set('bulkEditNotes', '')
        ->call('bulkEdit')
        ->assertHasNoErrors();

    expect(currentVersionOf($row)->notes)->toBeNull()
        ->and(currentVersionOf($row)->description)->toBe('KEEP ME');
});

it('bulk edit applies a name but not a date to a bank feed row', function () {
    $feed = bulkFeedRow($this->user, $this->account, ['description' => 'BANK TEXT', 'post_date' => '2026-06-10']);

    bulkList($this->user, [$feed])
        ->set('bulkEditInclude', ['name', 'date'])
        ->set('bulkEditName', 'Tidy name')
        ->set('bulkEditDate', '2026-06-14')
        ->call('bulkEdit')
        ->assertHasNoErrors();

    $current = currentVersionOf($feed);

    expect($current->description)->toBe('BANK TEXT')
        ->and($current->clean_description)->toBe('Tidy name')
        ->and($current->clean_description_source)->toBe(CleanDescriptionSource::Manual)
        ->and($current->post_date->toDateString())->toBe('2026-06-10');
});

it('bulk edit skips a bank feed row when only the date is included', function () {
    $feed = bulkFeedRow($this->user, $this->account);
    $manual = bulkRow($this->user, $this->account);

    $component = bulkList($this->user, [$feed, $manual])
        ->set('bulkEditInclude', ['date'])
        ->set('bulkEditDate', '2026-06-14')
        ->call('bulkEdit');

    expect(currentVersionOf($feed)->id)->toBe($feed->id)
        ->and(currentVersionOf($manual)->post_date->toDateString())->toBe('2026-06-14')
        ->and($component->get('bulkNotice'))->toBe('Edited 1 transaction. Skipped 1 that cannot be edited.');
});

it('bulk edit skips split parents', function () {
    $category = Category::factory()->for($this->user)->create(['is_hidden' => false]);
    $split = bulkRow($this->user, $this->account, ['notes' => null]);
    $split->splits()->create(['category_id' => $category->id, 'amount' => 1000, 'position' => 1]);
    $plain = bulkRow($this->user, $this->account, ['notes' => null]);

    $component = bulkList($this->user, [$split, $plain])
        ->set('bulkEditInclude', ['notes'])
        ->set('bulkEditNotes', 'edited')
        ->call('bulkEdit');

    expect(currentVersionOf($split)->id)->toBe($split->id)
        ->and(currentVersionOf($split)->notes)->toBeNull()
        ->and(currentVersionOf($plain)->notes)->toBe('edited')
        ->and($component->get('bulkNotice'))->toBe('Edited 1 transaction. Skipped 1 that cannot be edited.');
});

it('bulk edit ignores another users transactions', function () {
    $intruder = User::factory()->create();
    $theirs = bulkRow($intruder, Account::factory()->for($intruder)->create(), ['notes' => 'theirs']);
    $mine = bulkRow($this->user, $this->account, ['notes' => null]);

    bulkList($this->user, [$mine, $theirs])
        ->set('bulkEditInclude', ['notes'])
        ->set('bulkEditNotes', 'mine now')
        ->call('bulkEdit');

    expect(currentVersionOf($mine)->notes)->toBe('mine now')
        ->and(currentVersionOf($theirs)->id)->toBe($theirs->id)
        ->and($theirs->fresh()->notes)->toBe('theirs');
});

it('bulk edit refuses a request that includes no field', function () {
    $row = bulkRow($this->user, $this->account);

    bulkList($this->user, [$row])
        ->set('bulkEditNotes', 'ignored')
        ->call('bulkEdit')
        ->assertHasErrors(['bulkEditInclude']);

    expect(currentVersionOf($row)->id)->toBe($row->id);
});

it('bulk edit validates the included fields and nothing else', function () {
    $row = bulkRow($this->user, $this->account);

    $component = bulkList($this->user, [$row])
        ->set('bulkEditInclude', ['date', 'name'])
        ->set('bulkEditDate', 'not a date')
        ->set('bulkEditName', '   ')
        ->call('bulkEdit')
        ->assertHasErrors(['bulkEditDate', 'bulkEditName']);

    expect(currentVersionOf($row)->id)->toBe($row->id);

    $component->set('bulkEditInclude', ['notes'])
        ->call('bulkEdit')
        ->assertHasNoErrors();

    expect(currentVersionOf($row)->id)->not->toBe($row->id);
});

it('bulk edit moves balance history with a changed date inside one transaction', function () {
    $account = bulkHistoryAccount($this->user);
    $row = bulkHistoryRow($this->user, $account, '2026-06-05', 2000);
    $other = bulkHistoryRow($this->user, $account, '2026-06-06', 1000);

    $outermost = outermostTransactionsDuring(fn () => bulkList($this->user, [$row, $other])
        ->set('bulkEditInclude', ['date'])
        ->set('bulkEditDate', '2026-06-12')
        ->call('bulkEdit'));

    expect($outermost)->toBe(1)
        ->and($account->dailyBalances()->whereDate('balance_date', '2026-06-11')->sole()->end_balance)->toBe(100000)
        ->and($account->dailyBalances()->whereDate('balance_date', '2026-06-12')->sole()->end_balance)->toBe(97000);

    expectBalanceHistoryConsistent($account);
});

it('bulk edit keeps a transfer pair linked to the edited version', function () {
    $other = Account::factory()->for($this->user)->create();
    [$debit, $credit] = bulkTransferPair($this->user, $this->account, $other);

    bulkList($this->user, [$debit])
        ->set('bulkEditInclude', ['notes'])
        ->set('bulkEditNotes', 'moved to savings')
        ->call('bulkEdit');

    $child = currentVersionOf($debit);

    expect($child->id)->not->toBe($debit->id)
        ->and($child->transfer_pair_id)->toBe($credit->id)
        ->and($credit->fresh()->transfer_pair_id)->toBe($child->id);
});

it('bulk edit leaves the date of a linked transfer leg alone but still applies its notes', function () {
    $other = Account::factory()->for($this->user)->create();
    [$debit, $credit] = bulkTransferPair($this->user, $this->account, $other);

    $component = bulkList($this->user, [$debit])
        ->set('bulkEditInclude', ['date'])
        ->set('bulkEditDate', '2026-06-14')
        ->call('bulkEdit');

    expect(currentVersionOf($debit)->id)->toBe($debit->id)
        ->and($component->get('bulkNotice'))->toBe('Edited 0 transactions. Skipped 1 that cannot be edited.');

    bulkList($this->user, [$debit])
        ->set('bulkEditInclude', ['date', 'notes'])
        ->set('bulkEditDate', '2026-06-14')
        ->set('bulkEditNotes', 'kept pair')
        ->call('bulkEdit');

    $child = currentVersionOf($debit);

    expect($child->notes)->toBe('kept pair')
        ->and($child->post_date->toDateString())->toBe('2026-06-10')
        ->and($credit->fresh()->post_date->toDateString())->toBe('2026-06-10');
});

it('records an audit event for a bulk edit', function () {
    $row = bulkRow($this->user, $this->account);

    bulkList($this->user, [$row])
        ->set('bulkEditInclude', ['notes'])
        ->set('bulkEditNotes', 'audited')
        ->call('bulkEdit');

    $event = AuditEvent::query()->where('action', 'livewire.transaction-list.bulkEdit')->sole();

    expect($event->user_id)->toBe($this->user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success);
});

it('records an audit event for a bulk delete', function () {
    $row = bulkRow($this->user, $this->account);

    bulkList($this->user, [$row])->call('bulkDelete');

    expect(AuditEvent::query()->where('action', 'livewire.transaction-list.bulkDelete')->sole()->user_id)
        ->toBe($this->user->id);
});

it('records a rejected bulk edit as a failure', function () {
    $row = bulkRow($this->user, $this->account);

    bulkList($this->user, [$row])->call('bulkEdit');

    expect(AuditEvent::query()->where('action', 'livewire.transaction-list.bulkEdit')->sole()->outcome)
        ->toBe(AuditOutcome::Failure);
});

it('the delete panel counts what will be deleted and what is skipped', function () {
    $manual = collect(range(1, 3))->map(fn (): Transaction => bulkRow($this->user, $this->account));
    $feed = bulkFeedRow($this->user, $this->account);

    $component = bulkList($this->user, $manual->push($feed))->call('openBulkPanel', 'delete');

    expect($component->viewData('bulkImpact'))->toBe(['requested' => 4, 'changes' => 3, 'skipped' => 1, 'keepDate' => 0]);
});

it('the edit panel counts the rows the included fields can change', function () {
    $manual = bulkRow($this->user, $this->account);
    $feed = bulkFeedRow($this->user, $this->account);

    $component = bulkList($this->user, [$manual, $feed])->call('openBulkPanel', 'edit');

    expect($component->viewData('bulkImpact'))->toBe(['requested' => 2, 'changes' => 0, 'skipped' => 2, 'keepDate' => 0]);

    $component->set('bulkEditInclude', ['date']);
    expect($component->viewData('bulkImpact'))->toBe(['requested' => 2, 'changes' => 1, 'skipped' => 1, 'keepDate' => 0]);

    $component->set('bulkEditInclude', ['date', 'notes']);
    expect($component->viewData('bulkImpact'))->toBe(['requested' => 2, 'changes' => 2, 'skipped' => 0, 'keepDate' => 1]);

    $component->set('bulkEditInclude', ['notes']);
    expect($component->viewData('bulkImpact'))->toBe(['requested' => 2, 'changes' => 2, 'skipped' => 0, 'keepDate' => 0]);
});

it('a transfer leg and a split row can be ticked for bulk actions', function () {
    $other = Account::factory()->for($this->user)->create();
    [$debit] = bulkTransferPair($this->user, $this->account, $other);
    $split = bulkRow($this->user, $this->account);
    $split->splits()->create([
        'category_id' => Category::factory()->for($this->user)->create(['is_hidden' => false])->id,
        'amount' => 1000,
        'position' => 1,
    ]);

    $component = bulkList($this->user, [$debit, $split]);

    expect($component->viewData('selectionCount'))->toBe(2)
        ->and($component->viewData('categorisableCount'))->toBe(0);

    $component->call('bulkDelete');

    expect(Transaction::query()->find($split->id))->toBeNull();
});

it('bulk delete of select-all-matching removes every matching row except the unticked and the kept', function () {
    $rows = collect(range(1, 30))->map(fn (): Transaction => bulkRow($this->user, $this->account));
    $feed = bulkFeedRow($this->user, $this->account);
    $unticked = $rows->first();

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->call('selectAllMatching')
        ->call('toggleVisible', [$unticked->id], false)
        ->call('openBulkPanel', 'delete');

    expect($component->viewData('bulkImpact'))->toBe(['requested' => 30, 'changes' => 29, 'skipped' => 1, 'keepDate' => 0]);

    $component->call('bulkDelete');

    expect(Transaction::query()->where('user_id', $this->user->id)->pluck('id')->all())
        ->toEqualCanonicalizing([$unticked->id, $feed->id]);
});

it('the edit panel asks what to change until a field is chosen', function () {
    $component = bulkList($this->user, [bulkRow($this->user, $this->account)])
        ->call('openBulkPanel', 'edit');

    $component->assertSeeHtml('data-testid="bulk-impact-choose"')
        ->assertDontSeeHtml('data-testid="bulk-impact"');

    $component->set('bulkEditInclude', ['notes'])
        ->assertSeeHtml('data-testid="bulk-impact"');
});
