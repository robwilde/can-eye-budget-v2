<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\StatementLineKind;
use App\Enums\StatementLineResolution;
use App\Enums\StatementReconciliationStatus;
use App\Enums\TransactionSource;
use App\Livewire\ReconcileStatement;
use App\Models\Account;
use App\Models\RedbarkAccount;
use App\Models\RedbarkFeed;
use App\Models\StatementReconciliation;
use App\Models\StatementReconciliationLine;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-09-27 10:00:00');
});

/** @return array{0: User, 1: Account} */
function reconcileAccount(): array
{
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Everyday']);
    RedbarkAccount::factory()->create([
        'redbark_feed_id' => RedbarkFeed::factory()->create(['user_id' => $user->id])->id,
        'account_id' => $account->id,
    ]);

    return [$user, $account];
}

/** An open August 2026 reconciliation (the default month under the frozen clock). */
function reconcileAugust(Account $account): StatementReconciliation
{
    return StatementReconciliation::factory()->create([
        'user_id' => $account->user_id,
        'account_id' => $account->id,
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
    ]);
}

function reconcileLine(StatementReconciliation $reconciliation, string $state): StatementReconciliationLine
{
    return StatementReconciliationLine::factory()->for($reconciliation, 'reconciliation')->{$state}()->create([
        'post_date' => '2026-08-10',
    ]);
}

function reconcilePage(User $user, Account $account, array $query = []): Testable
{
    return Livewire::actingAs($user)->withQueryParams($query)->test(ReconcileStatement::class, ['account' => $account]);
}

function reconcileCsv(string $name, string $contents): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

test('another user\'s account is not found', function () {
    [, $account] = reconcileAccount();

    $this->actingAs(User::factory()->create())
        ->get(route('accounts.reconcile', $account))
        ->assertNotFound();
});

test('an account without a redbark link is not found', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('accounts.reconcile', $account))
        ->assertNotFound();
});

test('the page renders and defaults to last month', function () {
    [$user, $account] = reconcileAccount();

    $this->actingAs($user)
        ->get(route('accounts.reconcile', $account))
        ->assertOk()
        ->assertSee('Reconcile Everyday')
        ->assertSee('data-testid="reconcile-upload"', false);

    reconcilePage($user, $account)->assertSet('month', '2026-08');
});

test('uploading, mapping and reconciling the Beyond Bank statement builds the lines', function () {
    [$user, $account] = reconcileAccount();
    $netflix = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'post_date' => '2026-01-01',
        'description' => 'VISA -Netflix.com Melbourne AU 724493 #2892',
        'amount' => -2899,
        'source' => TransactionSource::Redbark,
    ]);

    reconcilePage($user, $account, ['month' => '2026-01'])
        ->assertSet('month', '2026-01')
        ->set('file', reconcileCsv('beyond.csv', (string) file_get_contents(base_path('tests/Fixtures/StatementCsv-BeyondBank.csv'))))
        ->call('uploadFile')
        ->assertHasNoErrors()
        ->assertSet('headers', ['Effective Date', 'Entered Date', 'Transaction Description', 'Amount', 'Balance'])
        ->set('mapping.date', 'Entered Date')
        ->set('mapping.description', 'Transaction Description')
        ->set('mapping.amount', 'Amount')
        ->set('mapping.balance', 'Balance')
        ->call('reconcile')
        ->assertHasNoErrors()
        ->assertSet('errorMessage', null)
        ->assertSee('data-testid="reconcile-section-statement-only"', false);

    $reconciliation = StatementReconciliation::query()->where('account_id', $account->id)->sole();

    expect($reconciliation->period_start->toDateString())->toBe('2026-01-01')
        ->and($reconciliation->period_end->toDateString())->toBe('2026-01-31')
        ->and($reconciliation->original_filename)->toBe('beyond.csv')
        ->and($reconciliation->lines_outside_period)->toBeGreaterThan(0)
        ->and($reconciliation->lines()->where('kind', StatementLineKind::Matched)->pluck('transaction_id')->all())->toBe([$netflix->id])
        ->and($reconciliation->lines()->where('kind', StatementLineKind::StatementOnly)->count())->toBeGreaterThan(0)
        ->and($account->fresh()->column_mapping['date'])->toBe('Entered Date');
});

test('re-uploading an open month rebuilds its lines', function () {
    [$user, $account] = reconcileAccount();
    $reconciliation = reconcileAugust($account);
    $old = reconcileLine($reconciliation, 'statementOnly');

    reconcilePage($user, $account)
        ->call('startUpload')
        ->set('file', reconcileCsv('august.csv', "Date,Description,Amount\n05/08/2026,KMART 1111,-9.00\n06/08/2026,KMART 2222,-4.00\n"))
        ->call('uploadFile')
        ->call('reconcile')
        ->assertSet('uploading', false)
        ->assertSet('errorMessage', null);

    $reconciliation->refresh();

    expect($reconciliation->original_filename)->toBe('august.csv')
        ->and($reconciliation->lines()->pluck('description')->all())->toBe(['KMART 1111', 'KMART 2222'])
        ->and(StatementReconciliationLine::query()->find($old->id))->toBeNull()
        ->and(StatementReconciliation::query()->count())->toBe(1);
});

test('a month closed after the upload refuses the reconcile', function () {
    [$user, $account] = reconcileAccount();
    $reconciliation = reconcileAugust($account);
    $line = reconcileLine($reconciliation, 'matched');

    $page = reconcilePage($user, $account)
        ->call('startUpload')
        ->set('file', reconcileCsv('august.csv', "Date,Description,Amount\n05/08/2026,KMART 1111,-9.00\n"))
        ->call('uploadFile');

    $reconciliation->update(['status' => StatementReconciliationStatus::Closed, 'closed_at' => now()]);

    $page->call('reconcile')->assertSet('errorMessage', 'This month is closed — reopen it first');

    expect($reconciliation->lines()->pluck('id')->all())->toBe([$line->id])
        ->and($reconciliation->fresh()->original_filename)->toBe('statement.csv');
});

test('tick and untick persist checked_at', function () {
    [$user, $account] = reconcileAccount();
    $line = reconcileLine(reconcileAugust($account), 'matched');

    $page = reconcilePage($user, $account)->call('tick', $line->id);
    expect($line->fresh()->isChecked())->toBeTrue();

    $page->call('untick', $line->id);
    expect($line->fresh()->isChecked())->toBeFalse();
});

test('tickAll ticks only lines of that kind', function () {
    [$user, $account] = reconcileAccount();
    $reconciliation = reconcileAugust($account);
    $matchedA = reconcileLine($reconciliation, 'matched');
    $matchedB = reconcileLine($reconciliation, 'matched');
    $feedOnly = reconcileLine($reconciliation, 'feedOnly');
    $statementOnly = reconcileLine($reconciliation, 'statementOnly');

    reconcilePage($user, $account)->call('tickAll', 'matched');

    expect($matchedA->fresh()->isChecked())->toBeTrue()
        ->and($matchedB->fresh()->isChecked())->toBeTrue()
        ->and($feedOnly->fresh()->isChecked())->toBeFalse()
        ->and($statementOnly->fresh()->isChecked())->toBeFalse();

    reconcilePage($user, $account)->call('tickAll', 'feed_only')->call('tickAll', 'statement_only');

    expect($feedOnly->fresh()->isChecked())->toBeTrue()
        ->and($statementOnly->fresh()->isChecked())->toBeFalse();
});

test('add to account creates the transaction and flips the line', function () {
    [$user, $account] = reconcileAccount();
    $line = reconcileLine(reconcileAugust($account), 'statementOnly');

    reconcilePage($user, $account)->call('import', $line->id)->assertSet('errorMessage', null);

    $line->refresh();
    $transaction = Transaction::query()->where('account_id', $account->id)->sole();

    expect($line->kind)->toBe(StatementLineKind::Matched)
        ->and($line->resolution)->toBe(StatementLineResolution::Imported)
        ->and($line->transaction_id)->toBe($transaction->id)
        ->and($transaction->source)->toBe(TransactionSource::Csv)
        ->and($transaction->csv_hash)->toBe($line->csv_hash);
});

test('link merges a statement-only line with a feed-only line', function () {
    [$user, $account] = reconcileAccount();
    $reconciliation = reconcileAugust($account);
    $line = reconcileLine($reconciliation, 'statementOnly');
    $feedOnly = reconcileLine($reconciliation, 'feedOnly');

    reconcilePage($user, $account)->call('link', $line->id, $feedOnly->id)->assertSet('errorMessage', null);

    $line->refresh();

    expect($line->kind)->toBe(StatementLineKind::Matched)
        ->and($line->resolution)->toBe(StatementLineResolution::Linked)
        ->and($line->transaction_id)->toBe($feedOnly->transaction_id)
        ->and(StatementReconciliationLine::query()->find($feedOnly->id))->toBeNull();
});

test('ignore requires a note', function () {
    [$user, $account] = reconcileAccount();
    $line = reconcileLine(reconcileAugust($account), 'statementOnly');

    reconcilePage($user, $account)
        ->call('ignore', $line->id, '   ')
        ->assertHasErrors("ignore.{$line->id}")
        ->call('ignore', $line->id, 'Reversed next month')
        ->assertHasNoErrors();

    $line->refresh();

    expect($line->resolution)->toBe(StatementLineResolution::Ignored)
        ->and($line->note)->toBe('Reversed next month')
        ->and($line->isChecked())->toBeTrue();
});

test('close stays disabled until every line is ticked', function () {
    [$user, $account] = reconcileAccount();
    $reconciliation = reconcileAugust($account);
    $line = reconcileLine($reconciliation, 'matched');

    reconcilePage($user, $account)
        ->assertSeeHtmlInOrder(['data-testid="reconcile-close"'])
        ->assertSee('Tick every line to close the month.')
        ->call('close')
        ->assertSet('errorMessage', 'Every line must be checked before the reconciliation can close.');

    expect($reconciliation->fresh()->status)->toBe(StatementReconciliationStatus::Open);

    reconcilePage($user, $account)
        ->call('tick', $line->id)
        ->assertDontSee('Tick every line to close the month.')
        ->call('close')
        ->assertSet('errorMessage', null)
        ->assertSee('data-testid="reconcile-closed-banner"', false);

    expect($reconciliation->fresh()->status)->toBe(StatementReconciliationStatus::Closed);
});

test('a closed month rejects ticks until it is reopened', function () {
    [$user, $account] = reconcileAccount();
    $reconciliation = reconcileAugust($account);
    $line = reconcileLine($reconciliation, 'matched');
    $reconciliation->update(['status' => StatementReconciliationStatus::Closed, 'closed_at' => now()]);

    $page = reconcilePage($user, $account)
        ->call('tick', $line->id)
        ->assertSet('errorMessage', 'This month is closed — reopen it first');

    expect($line->fresh()->isChecked())->toBeFalse();

    $page->call('reopen')->assertDontSee('data-testid="reconcile-closed-banner"', false)->call('tick', $line->id);

    expect($reconciliation->fresh()->status)->toBe(StatementReconciliationStatus::Open)
        ->and($reconciliation->fresh()->closed_at)->toBeNull()
        ->and($line->fresh()->isChecked())->toBeTrue();
});

test('line ids from another reconciliation are rejected', function () {
    [$user, $account] = reconcileAccount();
    reconcileAugust($account);
    $foreign = StatementReconciliationLine::factory()->statementOnly()->create();
    $foreignFeedOnly = StatementReconciliationLine::factory()->feedOnly()->create();
    $own = reconcileLine($account->statementReconciliations()->sole(), 'statementOnly');

    reconcilePage($user, $account)
        ->call('tick', $foreign->id)
        ->assertSet('errorMessage', 'That line is not part of this reconciliation.')
        ->call('import', $foreign->id)
        ->call('ignore', $foreign->id, 'nope')
        ->call('link', $own->id, $foreignFeedOnly->id)
        ->assertSet('errorMessage', 'That line is not part of this reconciliation.');

    $foreign->refresh();

    expect($foreign->isChecked())->toBeFalse()
        ->and($foreign->kind)->toBe(StatementLineKind::StatementOnly)
        ->and($foreign->resolution)->toBeNull()
        ->and($own->fresh()->kind)->toBe(StatementLineKind::StatementOnly)
        ->and(StatementReconciliationLine::query()->find($foreignFeedOnly->id))->not->toBeNull();
});

test('a line the reconciler cannot resolve surfaces an inline error', function () {
    [$user, $account] = reconcileAccount();
    $reconciliation = reconcileAugust($account);
    $matched = reconcileLine($reconciliation, 'matched');
    $statementOnly = reconcileLine($reconciliation, 'statementOnly');
    $orphanFeedOnly = StatementReconciliationLine::factory()->for($reconciliation, 'reconciliation')->feedOnly()->create([
        'post_date' => '2026-08-10',
        'transaction_id' => null,
    ]);

    reconcilePage($user, $account)
        ->call('ignore', $matched->id, 'Not needed')
        ->assertSet('errorMessage', 'A matched line has no discrepancy to ignore.')
        ->assertSee('A matched line has no discrepancy to ignore.')
        ->call('link', $statementOnly->id, $orphanFeedOnly->id)
        ->assertSet('errorMessage', 'The feed transaction behind this line no longer exists.');

    expect($matched->fresh()->resolution)->toBeNull()
        ->and($statementOnly->fresh()->kind)->toBe(StatementLineKind::StatementOnly)
        ->and($orphanFeedOnly->fresh())->not->toBeNull();
});

test('a saved mapping only applies to columns this file still has', function () {
    [$user, $account] = reconcileAccount();
    $account->update(['column_mapping' => ['date' => 'Entered Date', 'description' => 'Description', 'amount' => 'Amount']]);

    reconcilePage($user, $account)
        ->set('file', reconcileCsv('august.csv', "Date,Description,Amount\n05/08/2026,KMART 1111,-9.00\n"))
        ->call('uploadFile')
        ->assertSet('mapping.date', 'Date')
        ->assertSet('mapping.description', 'Description');
});

test('a mapping that names a column missing from the file is rejected', function () {
    [$user, $account] = reconcileAccount();

    reconcilePage($user, $account)
        ->set('file', reconcileCsv('august.csv', "Date,Description,Amount\n05/08/2026,KMART 1111,-9.00\n"))
        ->call('uploadFile')
        ->set('mapping.date', 'Entered Date')
        ->call('reconcile')
        ->assertHasErrors('mapping.date');

    expect(StatementReconciliation::query()->count())->toBe(0);
});

test('an unreadable statement is reported and the previous build is kept', function () {
    [$user, $account] = reconcileAccount();
    $reconciliation = reconcileAugust($account);
    Storage::disk('local')->put($reconciliation->stored_path, "Date,Description,Amount\n05/08/2026,KMART 1111,-9.00\n");
    $line = reconcileLine($reconciliation, 'statementOnly');

    $page = reconcilePage($user, $account)
        ->call('startUpload')
        ->set('file', reconcileCsv('broken.csv', "Date,Description,Amount\n05/08/2026,KMART 1111,-9.00\n06/08/2026,KMART 2222,\n"))
        ->call('uploadFile')
        ->call('reconcile');

    expect($page->get('errorMessage'))->toContain('could not be read')
        ->and($page->get('storedPath'))->not->toBeNull();

    $reconciliation->refresh();

    expect($reconciliation->original_filename)->toBe('statement.csv')
        ->and($reconciliation->lines()->pluck('id')->all())->toBe([$line->id])
        ->and(Storage::disk('local')->exists($reconciliation->stored_path))->toBeTrue();
});

test('an unresolved statement-only line cannot be ticked', function () {
    [$user, $account] = reconcileAccount();
    $reconciliation = reconcileAugust($account);
    $unresolved = reconcileLine($reconciliation, 'statementOnly');
    $ignored = reconcileLine($reconciliation, 'statementOnly');
    $ignored->update(['resolution' => StatementLineResolution::Ignored, 'note' => 'Bank error']);

    reconcilePage($user, $account)
        ->call('tick', $unresolved->id)
        ->assertSet('errorMessage', 'Add, link or ignore this statement line before ticking it.')
        ->call('tick', $ignored->id);

    expect($unresolved->fresh()->isChecked())->toBeFalse()
        ->and($ignored->fresh()->isChecked())->toBeTrue();
});

test('cancelling an upload deletes the pending file but keeps the reconciliation file', function () {
    [$user, $account] = reconcileAccount();
    $reconciliation = reconcileAugust($account);
    Storage::disk('local')->put($reconciliation->stored_path, "Date,Description,Amount\n");

    $page = reconcilePage($user, $account)
        ->call('startUpload')
        ->set('file', reconcileCsv('august.csv', "Date,Description,Amount\n05/08/2026,KMART 1111,-9.00\n"))
        ->call('uploadFile');
    $pending = $page->get('storedPath');

    $page->call('cancelUpload');

    expect(Storage::disk('local')->exists($pending))->toBeFalse()
        ->and(Storage::disk('local')->exists($reconciliation->stored_path))->toBeTrue();
});

test('uploading a second file before reconciling deletes the first pending file', function () {
    [$user, $account] = reconcileAccount();

    $page = reconcilePage($user, $account)
        ->set('file', reconcileCsv('first.csv', "Date,Description,Amount\n05/08/2026,KMART 1111,-9.00\n"))
        ->call('uploadFile');
    $first = $page->get('storedPath');

    $page->set('file', reconcileCsv('second.csv', "Date,Description,Amount\n05/08/2026,KMART 1111,-9.00\n"))
        ->call('uploadFile');

    expect(Storage::disk('local')->exists($first))->toBeFalse()
        ->and(Storage::disk('local')->exists($page->get('storedPath')))->toBeTrue();
});

test('a file cannot even be uploaded onto a closed month', function () {
    [$user, $account] = reconcileAccount();
    $reconciliation = reconcileAugust($account);
    $reconciliation->update(['status' => StatementReconciliationStatus::Closed, 'closed_at' => now()]);

    reconcilePage($user, $account)
        ->set('file', reconcileCsv('august.csv', "Date,Description,Amount\n05/08/2026,KMART 1111,-9.00\n"))
        ->call('uploadFile')
        ->assertSet('errorMessage', 'This month is closed — reopen it first')
        ->assertSet('storedPath', null)
        ->assertSet('headers', []);

    expect(Storage::disk('local')->allFiles('statement-reconciliations'))->toBe([]);
});

test('reopening and ticking all clear an earlier error', function () {
    [$user, $account] = reconcileAccount();
    $reconciliation = reconcileAugust($account);
    $line = reconcileLine($reconciliation, 'matched');
    $reconciliation->update(['status' => StatementReconciliationStatus::Closed, 'closed_at' => now()]);

    reconcilePage($user, $account)
        ->call('tick', $line->id)
        ->assertSet('errorMessage', 'This month is closed — reopen it first')
        ->call('reopen')
        ->assertSet('errorMessage', null)
        ->call('tick', 999999)
        ->assertSet('errorMessage', 'That line is not part of this reconciliation.')
        ->call('tickAll', 'matched')
        ->assertSet('errorMessage', null);
});
