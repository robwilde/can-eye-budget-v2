<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountClass;
use App\Enums\AccountGroup;
use App\Enums\ImportSource;
use App\Enums\TransferLinkSource;
use App\Livewire\AccountManager;
use App\Livewire\ImportBank;
use App\Livewire\PlannedTransactionManager;
use App\Livewire\RecurringTransactionReview;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reports\ReportAggregator;
use App\Services\Transfers\TransferLinker;
use App\Services\Transfers\UntrackedAccountCreator;
use App\Support\Calendar\DayActivityLoader;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

test('creator builds a manual untracked long-term account', function () {
    $user = User::factory()->create();

    $account = app(UntrackedAccountCreator::class)->create($user, 'Super', AccountClass::Investment, 250000);

    expect($account->is_tracked)->toBeFalse()
        ->and($account->group)->toBe(AccountGroup::LongTermSavings)
        ->and($account->import_source)->toBe(ImportSource::Manual)
        ->and($account->type)->toBe(AccountClass::Investment)
        ->and($account->balance)->toBe(250000);
});

test('untracked balance is excluded from available and owed totals', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 100000]);
    Account::factory()->for($user)->untracked()->create(['balance' => 900000]);

    expect($user->totalAvailable())->toBe(100000)
        ->and($user->totalOwed())->toBe(0);
});

test('untracked accounts are absent from import and planned pickers', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['name' => 'Everyday']);
    Account::factory()->for($user)->untracked()->create(['name' => 'Secret Stash']);

    Livewire::actingAs($user)->test(ImportBank::class)
        ->assertSee('Everyday')->assertDontSee('Secret Stash');
    Livewire::actingAs($user)->test(PlannedTransactionManager::class)
        ->assertSee('Everyday')->assertDontSee('Secret Stash');
    Livewire::actingAs($user)->test(RecurringTransactionReview::class)
        ->assertSee('Everyday')->assertDontSee('Secret Stash');
});

test('untracked transactions never count in calendar or report totals', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-15'));
    $user = User::factory()->create();
    $tracked = Account::factory()->for($user)->create();
    $hidden = Account::factory()->for($user)->untracked()->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $tracked->id, 'amount' => -1000, 'post_date' => '2026-07-05',
    ]);
    // Unpaired row on an untracked account: only the explicit tracked guard keeps it out.
    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $hidden->id, 'amount' => -7000, 'post_date' => '2026-07-05',
    ]);

    $activity = (new DayActivityLoader)->load(
        CarbonImmutable::parse('2026-07-01'),
        CarbonImmutable::parse('2026-07-31'),
        $user->id,
    );
    expect($activity['2026-07-05']->pips)->toHaveCount(1)
        ->and($activity['2026-07-05']->postedCents)->toBe(1000);

    $atoms = app(ReportAggregator::class)->atoms(
        $user, 'real', CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'),
    );
    expect(collect($atoms)->sum('total'))->toBe(1000);
});

test('linked transfers to an untracked account never change its balance', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['balance' => 500000]);
    $hidden = Account::factory()->for($user)->untracked()->create(['balance' => 100000]);
    $tx = Transaction::factory()->for($user)->debit()->create([
        'account_id' => $checking->id, 'amount' => -20000, 'post_date' => '2026-07-05',
    ]);

    $mirror = app(TransferLinker::class)->linkToUntrackedAccount($tx, $hidden, TransferLinkSource::Manual);

    expect($mirror->account_id)->toBe($hidden->id)
        ->and($hidden->refresh()->balance)->toBe(100000)
        ->and($user->totalAvailable())->toBe(500000);
});

test('a tracked leg linked to a hidden account keeps counting as spend in the transfer-aware totals', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-15'));
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create();
    $hidden = Account::factory()->for($user)->untracked()->create();
    $tx = Transaction::factory()->for($user)->debit()->create([
        'account_id' => $checking->id, 'amount' => -20000, 'post_date' => '2026-07-05',
    ]);
    $range = [CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31')];

    $before = (new DayActivityLoader)->load($range[0], $range[1], $user->id);
    expect($before['2026-07-05']->postedCents)->toBe(20000);

    app(TransferLinker::class)->linkToUntrackedAccount($tx, $hidden, TransferLinkSource::Manual);

    $after = (new DayActivityLoader)->load($range[0], $range[1], $user->id);
    $withTransfers = (new DayActivityLoader)->load($range[0], $range[1], $user->id, includeTransfers: true);
    $atoms = app(ReportAggregator::class)->atoms($user, 'real', $range[0], $range[1]);

    expect(isset($after['2026-07-05']) ? $after['2026-07-05']->postedCents : 0)->toBe(0)
        ->and($withTransfers['2026-07-05']->postedCents)->toBe(20000)
        ->and(collect($atoms)->sum('total'))->toBe(20000);
});

test('accounts page lists untracked accounts under long term savings with a badge', function () {
    $user = User::factory()->create();
    $hidden = Account::factory()->for($user)->untracked()->create(['name' => 'Secret Stash']);

    Livewire::actingAs($user)->test(AccountManager::class)
        ->assertSee('Secret Stash')
        ->assertSee('Long Term Savings')
        ->assertSeeHtml('data-test="account-untracked-'.$hidden->id.'"');
});

test('accounts page creates and edits an untracked account', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openAddUntrackedModal')
        ->set('name', 'Term Deposit')
        ->set('balance', '1500.50')
        ->set('type', AccountClass::TermDeposit->value)
        ->set('description', 'Locked')
        ->call('save')
        ->assertHasNoErrors();

    $account = Account::query()->where('name', 'Term Deposit')->firstOrFail();
    expect($account->is_tracked)->toBeFalse()
        ->and($account->group)->toBe(AccountGroup::LongTermSavings)
        ->and($account->import_source)->toBe(ImportSource::Manual)
        ->and($account->balance)->toBe(150050)
        ->and($account->description)->toBe('Locked');

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openEditModal', $account->id)
        ->assertSet('isUntracked', true)
        ->set('name', 'TD 2')
        ->call('save');

    expect($account->refresh()->name)->toBe('TD 2')->and($account->is_tracked)->toBeFalse();
});

test('reconcile records date and difference', function () {
    $user = User::factory()->create();
    $hidden = Account::factory()->for($user)->untracked()->create(['balance' => 100000]);

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openReconcileModal', $hidden->id)
        ->set('reconcileBalance', '1250.00')
        ->set('reconcileDate', '2026-07-01')
        ->call('reconcile')
        ->assertHasNoErrors();

    $hidden->refresh();
    expect($hidden->balance)->toBe(125000)
        ->and($hidden->reconcile_difference)->toBe(25000)
        ->and($hidden->reconciled_on->toDateString())->toBe('2026-07-01');
});

test('reconcile and track cannot touch another users account', function () {
    $user = User::factory()->create();
    $other = Account::factory()->for(User::factory()->create())->untracked()->create(['balance' => 100]);

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openReconcileModal', $other->id)
        ->set('reconcilingAccountId', $other->id)
        ->set('reconcileBalance', '5')
        ->set('reconcileDate', '2026-07-01')
        ->call('reconcile')
        ->call('trackAccount', $other->id);

    expect($other->refresh()->is_tracked)->toBeFalse()
        ->and($other->reconciled_on)->toBeNull()
        ->and($other->balance)->toBe(100);
});

test('track this account converts to tracked and keeps transactions', function () {
    $user = User::factory()->create();
    $hidden = Account::factory()->for($user)->untracked()->create(['balance' => 100000]);
    $tx = Transaction::factory()->for($user)->create(['account_id' => $hidden->id]);

    Livewire::actingAs($user)->test(AccountManager::class)->call('trackAccount', $hidden->id);

    expect($hidden->refresh()->is_tracked)->toBeTrue()
        ->and($tx->fresh())->not->toBeNull()
        ->and($user->totalAvailable())->toBe(100000);
});

test('tracking an account keeps a hidden transfer paired when no real row exists to replace it', function () {
    $user = User::factory()->create();
    $optimus = Account::factory()->for($user)->create();
    $hidden = Account::factory()->for($user)->untracked()->create(['name' => 'uBank']);
    $original = Transaction::factory()->for($user)->fromRedbark()->create([
        'account_id' => $optimus->id,
        'direction' => App\Enums\TransactionDirection::Debit,
        'amount' => -50000,
        'post_date' => '2026-09-05',
        'description' => 'Transfer to uBank savings MOBILE#1111111111',
    ]);
    $mirror = app(TransferLinker::class)->linkToHiddenAccount($original, $hidden);

    Livewire::actingAs($user)->test(AccountManager::class)->call('trackAccount', $hidden->id);

    expect($hidden->fresh()->is_tracked)->toBeTrue()
        ->and(Transaction::query()->find($mirror->id))->not->toBeNull()
        ->and($original->fresh()->transfer_pair_id)->toBe($mirror->id)
        ->and($mirror->fresh()->transfer_pair_id)->toBe($original->id)
        ->and(Transaction::query()->where('user_id', $user->id)->excludingTransfers()->count())->toBe(0);
});

test('tracking an account replaces a mirror with the one real opposite row and links it directly', function () {
    $user = User::factory()->create();
    $optimus = Account::factory()->for($user)->create();
    $hidden = Account::factory()->for($user)->untracked()->create(['name' => 'uBank']);
    $original = Transaction::factory()->for($user)->fromRedbark()->create([
        'account_id' => $optimus->id,
        'direction' => App\Enums\TransactionDirection::Debit,
        'amount' => -50000,
        'post_date' => '2026-09-05',
        'description' => 'Transfer to uBank savings MOBILE#1111111111',
    ]);
    $mirror = app(TransferLinker::class)->linkToHiddenAccount($original, $hidden);
    $real = Transaction::factory()->for($user)->fromRedbark()->create([
        'account_id' => $hidden->id,
        'direction' => App\Enums\TransactionDirection::Credit,
        'amount' => 50000,
        'post_date' => '2026-09-06',
        'description' => 'Deposit',
    ]);

    Livewire::actingAs($user)->test(AccountManager::class)->call('trackAccount', $hidden->id);

    expect(Transaction::query()->find($mirror->id))->toBeNull()
        ->and($original->fresh()->transfer_pair_id)->toBe($real->id)
        ->and($real->fresh()->transfer_pair_id)->toBe($original->id)
        ->and(Transaction::query()->where('user_id', $user->id)->excludingTransfers()->count())->toBe(0);
});

test('a forged account mode cannot turn an untracked edit into a plain balance write', function () {
    $user = User::factory()->create();
    $hidden = Account::factory()->for($user)->untracked()->create(['balance' => 100000]);

    $component = Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openEditModal', $hidden->id);

    // The mode is server-owned: the client cannot flip it...
    expect(fn () => $component->set('isUntracked', false))
        ->toThrow(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

    // ...and the persisted account, not the form state, decides how a save is written.
    $component->set('balance', '1250.00')->call('save');

    $fresh = $hidden->refresh();
    expect($fresh->balance)->toBe(125000)
        ->and($fresh->reconcile_difference)->toBe(25000)
        ->and($fresh->reconciled_on)->not->toBeNull();
});

test('tracking an account keeps a transfer the user typed by hand on both sides', function () {
    $user = User::factory()->create();
    $main = Account::factory()->for($user)->create();
    $hidden = Account::factory()->for($user)->untracked()->create();
    $out = Transaction::factory()->for($user)->manual()->debit()->create(['account_id' => $main->id, 'amount' => -5000, 'post_date' => '2026-09-05']);
    $in = Transaction::factory()->for($user)->manual()->credit()->create(['account_id' => $hidden->id, 'amount' => 5000, 'post_date' => '2026-09-05']);
    app(TransferLinker::class)->link($out, $in, TransferLinkSource::Manual);

    Livewire::actingAs($user)->test(AccountManager::class)->call('trackAccount', $hidden->id);

    expect($hidden->fresh()->is_tracked)->toBeTrue()
        ->and(Transaction::query()->find($in->id))->not->toBeNull()
        ->and($out->fresh()->transfer_pair_id)->toBe($in->id)
        ->and($in->fresh()->transfer_pair_id)->toBe($out->id);
});

test('both track actions on the accounts page ask for confirmation', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->untracked()->create();

    $html = Livewire::actingAs($user)->test(AccountManager::class)->html();

    expect(preg_match_all('/wire:click="trackAccount\(\d+\)"/', $html))->toBe(2)
        ->and(mb_substr_count($html, 'wire:confirm="Track this account?'))->toBe(2);
});
