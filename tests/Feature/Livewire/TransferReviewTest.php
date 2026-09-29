<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Enums\TransferLinkSource;
use App\Livewire\Dashboard;
use App\Livewire\TransactionList;
use App\Livewire\TransferReview;
use App\Livewire\TransferRuleList;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransferRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

function reviewRow(User $user, Account $account, int $cents, string $date, string $description = 'Transfer to savings'): Transaction
{
    return Transaction::factory()->for($user)->fromRedbark()->create([
        'account_id' => $account->id,
        'direction' => $cents < 0 ? TransactionDirection::Debit : TransactionDirection::Credit,
        'amount' => $cents,
        'post_date' => $date,
        'description' => $description,
    ]);
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15'));
    $this->user = User::factory()->create();
    $this->optimus = Account::factory()->for($this->user)->create(['name' => 'Optimus']);
    $this->cc = Account::factory()->for($this->user)->create(['name' => 'CC']);
    $this->debit = reviewRow($this->user, $this->optimus, -5000, '2026-09-10');
    $this->credit = reviewRow($this->user, $this->cc, 5000, '2026-09-10');
    $this->artisan('transfers:detect')->assertSuccessful();
});

test('confirm links both legs, remembers rules both ways, and is idempotent', function () {
    expect($this->debit->fresh()->transfer_pair_id)->toBeNull();

    Livewire::actingAs($this->user)->test(TransferReview::class)
        ->assertSee('Optimus')
        ->call('confirm', $this->debit->id)
        ->call('confirm', $this->debit->id);

    expect($this->debit->fresh()->transfer_pair_id)->toBe($this->credit->id)
        ->and($this->credit->fresh()->transfer_pair_id)->toBe($this->debit->id)
        ->and($this->debit->fresh()->suggested_pair_id)->toBeNull()
        ->and($this->debit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Confirmed)
        ->and($this->credit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Confirmed)
        ->and(TransferRule::query()->count())->toBe(2)
        ->and(TransferRule::query()->where('account_id', $this->optimus->id)->where('counterpart_account_id', $this->cc->id)->exists())->toBeTrue()
        ->and(TransferRule::query()->where('account_id', $this->cc->id)->where('counterpart_account_id', $this->optimus->id)->exists())->toBeTrue();
});

test('after confirm a later matching import is auto-linked by rule', function () {
    Livewire::actingAs($this->user)->test(TransferReview::class)->call('confirm', $this->debit->id);

    $newDebit = reviewRow($this->user, $this->optimus, -7700, '2026-09-12');
    $newCredit = reviewRow($this->user, $this->cc, 7700, '2026-09-12');

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($newDebit->fresh()->transfer_pair_id)->toBe($newCredit->id)
        ->and($newDebit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Rule)
        ->and($newCredit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Rule);
});

test('not a transfer unlinks, keeps both rows and is never re-suggested', function () {
    Livewire::actingAs($this->user)->test(TransferReview::class)->call('notTransfer', $this->credit->id);

    $this->artisan('transfers:detect')->assertSuccessful();

    expect(Transaction::query()->count())->toBe(2)
        ->and($this->debit->fresh()->transfer_pair_id)->toBeNull()
        ->and($this->credit->fresh()->transfer_pair_id)->toBeNull()
        ->and($this->debit->fresh()->suggested_pair_id)->toBeNull()
        ->and($this->debit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Unlinked)
        ->and($this->credit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Unlinked);
});

test('another user cannot act on a transaction or rule', function () {
    $rule = TransferRule::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->optimus->id,
        'counterpart_account_id' => $this->cc->id,
        'description_pattern' => 'x',
    ]);
    $intruder = User::factory()->create();

    $component = Livewire::actingAs($intruder)->test(TransferReview::class);

    expect(fn () => $component->call('confirm', $this->debit->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $component->call('notTransfer', $this->debit->id))->toThrow(ModelNotFoundException::class);
    $ruleList = Livewire::actingAs($intruder)->test(TransferRuleList::class);
    expect(fn () => $ruleList->call('deleteRule', $rule->id))->toThrow(ModelNotFoundException::class);

    expect($rule->fresh())->not->toBeNull()
        ->and($this->debit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Suggested);
});

test('rules are listed and deleting one leaves existing links alone', function () {
    Livewire::actingAs($this->user)->test(TransferReview::class)->call('confirm', $this->debit->id);
    $rule = TransferRule::query()->where('account_id', $this->optimus->id)->firstOrFail();

    Livewire::actingAs($this->user)->test(TransferReview::class)->assertSee('Optimus → CC');
    Livewire::actingAs($this->user)->test(TransferRuleList::class)
        ->assertSee('Optimus → CC')
        ->call('deleteRule', $rule->id);

    expect(TransferRule::query()->find($rule->id))->toBeNull()
        ->and(TransferRule::query()->count())->toBe(1)
        ->and($this->debit->fresh()->transfer_pair_id)->toBe($this->credit->id);
});

test('link to hidden account creates the mirror and a rule that auto-links later rows', function () {
    $transferCategory = Category::factory()->create(['name' => 'Transfer']);
    $lone = reviewRow($this->user, $this->optimus, -30000, '2026-09-11', 'Pot top up');
    $lone->forceFill(['category_id' => $transferCategory->id])->save();

    Livewire::actingAs($this->user)->test(TransferReview::class)
        ->assertSee('Pot top up')
        ->call('startLinkHidden', $lone->id)
        ->set('newAccountName', 'Holiday pot')
        ->call('linkToHidden');

    $hidden = Account::query()->where('name', 'Holiday pot')->firstOrFail();
    $mirror = Transaction::query()->findOrFail($lone->fresh()->transfer_pair_id);

    expect($hidden->is_tracked)->toBeFalse()
        ->and($mirror->account_id)->toBe($hidden->id)
        ->and($mirror->amount)->toBe(30000)
        ->and($lone->fresh()->transfer_link_source)->toBe(TransferLinkSource::Confirmed)
        ->and(TransferRule::query()->where('account_id', $this->optimus->id)->where('counterpart_account_id', $hidden->id)->exists())->toBeTrue();

    $next = reviewRow($this->user, $this->optimus, -12000, '2026-09-14', 'Pot top up');
    $this->artisan('transfers:detect')->assertSuccessful();
    $this->artisan('transfers:detect')->assertSuccessful();

    $nextMirror = Transaction::query()->findOrFail($next->fresh()->transfer_pair_id);
    expect($next->fresh()->transfer_link_source)->toBe(TransferLinkSource::Rule)
        ->and($nextMirror->account_id)->toBe($hidden->id)
        ->and(Transaction::query()->where('account_id', $hidden->id)->count())->toBe(2);
});

test('link to hidden account can reuse an existing hidden account but not a tracked or foreign one', function () {
    $lone = reviewRow($this->user, $this->optimus, -900, '2026-09-11', 'Transfer to pot');
    $hidden = Account::factory()->for($this->user)->untracked()->create();
    $foreign = Account::factory()->for(User::factory()->create())->untracked()->create();

    $component = Livewire::actingAs($this->user)->test(TransferReview::class)->call('startLinkHidden', $lone->id);

    expect(fn () => $component->set('hiddenAccountId', (string) $foreign->id)->call('linkToHidden'))->toThrow(ModelNotFoundException::class);
    expect(fn () => $component->set('hiddenAccountId', (string) $this->cc->id)->call('linkToHidden'))->toThrow(ModelNotFoundException::class);

    $component->set('hiddenAccountId', (string) $hidden->id)->call('linkToHidden');

    expect(Transaction::query()->findOrFail($lone->fresh()->transfer_pair_id)->account_id)->toBe($hidden->id);
});

test('transaction list suggested filter shows only suggested rows and hides mirror legs', function () {
    $confirmed = reviewRow($this->user, $this->optimus, -1234, '2026-09-11', 'Confirmed one');
    app(App\Services\Transfers\TransferLinker::class)
        ->linkToHiddenAccount($confirmed, Account::factory()->for($this->user)->untracked()->create(['name' => 'Hidden pot']));
    $plain = reviewRow($this->user, $this->optimus, -400, '2026-09-11', 'Plain coffee');

    Livewire::actingAs($this->user)->test(TransactionList::class)
        ->assertSee('Plain coffee')
        ->assertSee('Confirmed one')
        ->assertViewHas('suggestedTransferCount', 1)
        ->set('transfers', 'suggested')
        ->assertDontSee('Plain coffee')
        ->assertDontSee('Confirmed one')
        ->assertSee('Transfer to savings');

    $mirrorCount = Livewire::actingAs($this->user)->test(TransactionList::class)->set('period', 'all')->viewData('transactions')->total();

    expect($mirrorCount)->toBe(4)
        ->and($plain->fresh())->not->toBeNull();
});

test('a pending suggestion carries a possible-transfer badge that disappears once confirmed', function () {
    Livewire::actingAs($this->user)->test(TransactionList::class)
        ->assertSeeHtml('data-testid="possible-transfer-'.$this->debit->id.'"')
        ->assertSeeHtml('data-testid="possible-transfer-'.$this->credit->id.'"');

    app(App\Services\Transfers\TransferLinker::class)->confirm($this->debit->fresh());

    Livewire::actingAs($this->user)->test(TransactionList::class)
        ->assertDontSeeHtml('data-testid="possible-transfer-'.$this->debit->id.'"')
        ->assertViewHas('suggestedTransferCount', 0);
});

test('a row marked not a transfer does not reappear under unmatched transfers', function () {
    $category = Category::factory()->create(['name' => 'Transfer']);
    $this->debit->forceFill(['category_id' => $category->id])->save();

    $component = Livewire::actingAs($this->user)->test(TransferReview::class);
    $component->call('notTransfer', $this->debit->id);

    Livewire::actingAs($this->user)->test(TransferReview::class)
        ->assertSee('No unmatched transfers');
});

test('the dashboard prompts to review pending transfers and stays quiet without any', function () {
    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->assertSeeHtml('data-test="dashboard-pending-transfers-card"')
        ->assertSee('1 possible transfer to review');

    app(App\Services\Transfers\TransferLinker::class)->confirm($this->debit->fresh());

    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->assertDontSeeHtml('data-test="dashboard-pending-transfers-card"');
});

test('transfer rules are listed on the rules page next to the user rules', function () {
    app(App\Services\Transfers\TransferLinker::class)->confirm($this->debit->fresh());

    $this->actingAs($this->user)->get(route('rules'))
        ->assertOk()
        ->assertSee('Transfer rules')
        ->assertSee('Optimus → CC');
});

test('untracked accounts are not offered in the transaction list account filter', function () {
    Account::factory()->for($this->user)->untracked()->create(['name' => 'Spaceship']);

    Livewire::actingAs($this->user)->test(TransactionList::class)
        ->assertViewHas('accounts', fn ($accounts) => $accounts->pluck('name')->doesntContain('Spaceship')
            && $accounts->pluck('name')->contains('Optimus'));
});

test('an unmatched transfer row can be dismissed as not a transfer and then counts normally', function () {
    $category = Category::factory()->create(['name' => 'Transfer']);
    $lone = reviewRow($this->user, $this->optimus, -12300, '2026-09-12', 'Lone transfer row');
    $lone->forceFill(['category_id' => $category->id])->save();

    Livewire::actingAs($this->user)->test(TransferReview::class)
        ->assertSee('Lone transfer row')
        ->assertSeeHtml('data-testid="unmatched-not-transfer-'.$lone->id.'"')
        ->call('notTransfer', $lone->id)
        ->assertDontSee('Lone transfer row');

    expect($lone->fresh()->transfer_link_source)->toBe(TransferLinkSource::Unlinked)
        ->and(Transaction::query()->whereKey($lone->id)->excludingTransfers()->exists())->toBeTrue();
});

test('confirming a pair that changed under the reviewer reports it and creates no rules', function () {
    $rival = reviewRow($this->user, $this->optimus, -5000, '2026-09-11');
    $component = Livewire::actingAs($this->user)->test(TransferReview::class);

    app(App\Services\Transfers\TransferLinker::class)->link($rival, $this->credit->fresh(), TransferLinkSource::Manual);
    $component->call('confirm', $this->debit->id);

    expect(TransferRule::query()->count())->toBe(0)
        ->and($this->debit->fresh()->transfer_pair_id)->toBeNull();
});

test('linking to a hidden account is refused while real opposite rows exist', function () {
    $category = Category::factory()->create(['name' => 'Transfer']);
    $lone = reviewRow($this->user, $this->optimus, -9900, '2026-09-11', 'Ambiguous transfer');
    $lone->forceFill(['category_id' => $category->id])->save();
    // Two equally good real credits: ambiguous, so it is never suggested and lands in Unmatched.
    reviewRow($this->user, $this->cc, 9900, '2026-09-11');
    reviewRow($this->user, Account::factory()->for($this->user)->create(), 9900, '2026-09-12');
    $hidden = Account::factory()->for($this->user)->untracked()->create(['name' => 'Pot']);

    Livewire::actingAs($this->user)->test(TransferReview::class)
        ->assertSee('Ambiguous transfer')
        ->call('startLinkHidden', $lone->id)
        ->set('hiddenAccountId', (string) $hidden->id)
        ->call('linkToHidden')
        ->assertHasErrors('hiddenAccountId');

    expect(Transaction::query()->where('account_id', $hidden->id)->count())->toBe(0)
        ->and($lone->fresh()->transfer_pair_id)->toBeNull();
});

test('a refused hidden-account link explains itself and rolls back a newly created account', function () {
    $category = Category::factory()->create(['name' => 'Transfer']);
    $lone = reviewRow($this->user, $this->optimus, -9900, '2026-09-11', 'Ambiguous transfer');
    $lone->forceFill(['category_id' => $category->id])->save();
    reviewRow($this->user, $this->cc, 9900, '2026-09-11');

    Livewire::actingAs($this->user)->test(TransferReview::class)
        ->call('startLinkHidden', $lone->id)
        ->set('newAccountName', 'Brand new pot')
        ->call('linkToHidden')
        ->assertHasErrors('hiddenAccountId')
        ->assertSee('A matching transaction exists');

    // No hidden account existed, so the field error must show without the picker, and the
    // account created for the attempt is rolled back with the refused link.
    expect(Account::query()->where('name', 'Brand new pot')->exists())->toBeFalse();
});

test('the unmatched queue is the bank-feed transfer signal: description or category, imported rows only', function () {
    $category = Category::factory()->create(['name' => 'Transfer']);
    reviewRow($this->user, $this->optimus, -1111, '2026-09-12', 'Transfer from savings xxxx1');
    $byCategory = reviewRow($this->user, $this->optimus, -2222, '2026-09-12', 'Optmus to CC');
    $byCategory->forceFill(['category_id' => $category->id])->save();
    reviewRow($this->user, $this->optimus, -3333, '2026-09-12', 'Coffee shop');
    Transaction::factory()->for($this->user)->manual()->debit()->create([
        'account_id' => $this->optimus->id, 'amount' => -4444, 'post_date' => '2026-09-12', 'description' => 'Transfer typed by hand',
    ]);
    Transaction::factory()->for($this->user)->fromCsv()->debit()->create([
        'account_id' => $this->optimus->id, 'amount' => -5555, 'post_date' => '2026-09-12', 'description' => 'Transfer csv row',
    ]);

    Livewire::actingAs($this->user)->test(TransferReview::class)
        ->assertSee('Transfer from savings xxxx1')
        ->assertSee('Optmus to CC')
        ->assertDontSee('Coffee shop')
        ->assertDontSee('Transfer typed by hand')
        ->assertDontSee('Transfer csv row');
});

test('a forged id cannot link a row that is not in the unmatched queue to a hidden account', function () {
    $manual = Transaction::factory()->for($this->user)->manual()->debit()->create([
        'account_id' => $this->optimus->id, 'amount' => -4444, 'post_date' => '2026-09-12', 'description' => 'Plain manual spend',
    ]);
    $plainFeed = reviewRow($this->user, $this->optimus, -3333, '2026-09-12', 'Coffee shop');
    $hidden = Account::factory()->for($this->user)->untracked()->create();

    $component = Livewire::actingAs($this->user)->test(TransferReview::class);

    foreach ([$manual, $plainFeed] as $row) {
        expect(fn () => $component->call('startLinkHidden', $row->id))->toThrow(ModelNotFoundException::class);
    }

    expect(Transaction::query()->where('account_id', $hidden->id)->count())->toBe(0)
        ->and(TransferRule::query()->count())->toBe(0);
});

test('a forged Not a transfer cannot stamp a row that is not in the review queue', function () {
    $manual = Transaction::factory()->for($this->user)->manual()->debit()->create([
        'account_id' => $this->optimus->id, 'amount' => -4444, 'post_date' => '2026-09-12', 'description' => 'Plain manual spend',
    ]);
    $plainFeed = reviewRow($this->user, $this->optimus, -3333, '2026-09-12', 'Coffee shop');
    $component = Livewire::actingAs($this->user)->test(TransferReview::class);

    foreach ([$manual, $plainFeed] as $row) {
        expect(fn () => $component->call('notTransfer', $row->id))->toThrow(ModelNotFoundException::class)
            ->and($row->fresh()->transfer_link_source)->toBeNull();
    }
});
