<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PipelineTrigger;
use App\Enums\TransactionDirection;
use App\Enums\TransferLinkSource;
use App\Exceptions\AccountNotUntrackedException;
use App\Exceptions\TransferAlreadyLinkedException;
use App\Models\Account;
use App\Models\Category;
use App\Models\PipelineAuditEntry;
use App\Models\Transaction;
use App\Models\TransferRule;
use App\Models\User;
use App\Services\Reports\ReportAggregator;
use App\Services\TransactionAnalysisPipeline;
use App\Services\Transfers\TransferDetector;
use App\Services\Transfers\TransferLinker;
use App\Services\Transfers\TransferReviewQueue;
use App\Support\Calendar\DayActivityLoader;
use Carbon\CarbonImmutable;

function feedRow(User $user, Account $account, int $cents, string $date, string $description = 'Transfer'): Transaction
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
    $this->user = User::factory()->create();
    $this->optimus = Account::factory()->for($this->user)->create(['name' => 'Optimus']);
    $this->cc = Account::factory()->for($this->user)->create(['name' => 'CC']);
});

test('suggests a single mutual pair on both legs without linking it', function () {
    $debit = feedRow($this->user, $this->optimus, -252100, '2026-09-10');
    $credit = feedRow($this->user, $this->cc, 252100, '2026-09-11');

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($debit->fresh()->suggested_pair_id)->toBe($credit->id)
        ->and($credit->fresh()->suggested_pair_id)->toBe($debit->id)
        ->and($debit->fresh()->transfer_pair_id)->toBeNull()
        ->and($credit->fresh()->transfer_pair_id)->toBeNull()
        ->and($debit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Suggested)
        ->and($credit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Suggested);
});

test('ambiguous repeated round ups produce no suggestion', function () {
    feedRow($this->user, $this->optimus, -150, '2026-09-10', 'Round Up transfer to xxxx4599');
    feedRow($this->user, $this->optimus, -150, '2026-09-11', 'Round Up transfer to xxxx4599');
    feedRow($this->user, $this->cc, 150, '2026-09-10', 'Round Up transfer to xxxx4599');
    feedRow($this->user, $this->cc, 150, '2026-09-11', 'Round Up transfer to xxxx4599');

    $this->artisan('transfers:detect')->assertSuccessful();

    expect(Transaction::query()->whereNotNull('transfer_pair_id')->orWhereNotNull('suggested_pair_id')->count())->toBe(0);
});

test('one debit with two possible credits is not tie-broken', function () {
    feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    feedRow($this->user, $this->cc, 5000, '2026-09-10');
    $other = Account::factory()->for($this->user)->create();
    feedRow($this->user, $other, 5000, '2026-09-11');

    $this->artisan('transfers:detect')->assertSuccessful();

    expect(Transaction::query()->whereNotNull('transfer_pair_id')->orWhereNotNull('suggested_pair_id')->count())->toBe(0);
});

test('is idempotent', function () {
    feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    feedRow($this->user, $this->cc, 5000, '2026-09-10');

    $this->artisan('transfers:detect')->expectsOutputToContain('Linked 0 rule-based pair(s); suggested 1 pair(s) for review.')->assertSuccessful();
    $this->artisan('transfers:detect')->expectsOutputToContain('Linked 0 rule-based pair(s); suggested 0 pair(s) for review.')->assertSuccessful();
});

test('dry run reports but does not link', function () {
    feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    feedRow($this->user, $this->cc, 5000, '2026-09-10');

    $this->artisan('transfers:detect --dry-run')->expectsOutputToContain('Would link 0 rule-based pair(s); would suggest 1 pair(s) for review.')->assertSuccessful();

    expect(Transaction::query()->whereNotNull('transfer_pair_id')->orWhereNotNull('suggested_pair_id')->count())->toBe(0);
});

test('respects window, account, amount and user boundaries', function () {
    $otherUser = User::factory()->create();
    $otherAccount = Account::factory()->for($otherUser)->create();

    feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    feedRow($this->user, $this->cc, 5000, '2026-09-14');          // 4 days: outside window
    feedRow($this->user, $this->optimus, 5000, '2026-09-10');     // same account
    feedRow($this->user, $this->cc, 5001, '2026-09-10');          // different amount
    feedRow($otherUser, $otherAccount, 5000, '2026-09-10');       // other user

    $this->artisan('transfers:detect')->assertSuccessful();

    expect(Transaction::query()->whereNotNull('transfer_pair_id')->orWhereNotNull('suggested_pair_id')->count())->toBe(0);
});

test('window edge of three days links', function () {
    $debit = feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    $credit = feedRow($this->user, $this->cc, 5000, '2026-09-13');

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($debit->fresh()->suggested_pair_id)->toBe($credit->id);
});

test('a rejected suggestion is never suggested again', function () {
    $debit = feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    $credit = feedRow($this->user, $this->cc, 5000, '2026-09-10');
    $this->artisan('transfers:detect')->assertSuccessful();

    app(TransferLinker::class)->markNotTransfer($debit->fresh());
    $this->artisan('transfers:detect')->assertSuccessful();

    expect(Transaction::query()->count())->toBe(2)
        ->and($debit->fresh()->suggested_pair_id)->toBeNull()
        ->and($credit->fresh()->suggested_pair_id)->toBeNull()
        ->and($debit->fresh()->transfer_pair_id)->toBeNull()
        ->and($credit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Unlinked);
});

test('an unlinked confirmed pair is never re-suggested', function () {
    $debit = feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    $credit = feedRow($this->user, $this->cc, 5000, '2026-09-10');
    $this->artisan('transfers:detect')->assertSuccessful();
    app(TransferLinker::class)->confirm($debit->fresh());

    app(TransferLinker::class)->unlink($debit->fresh());
    $this->artisan('transfers:detect')->assertSuccessful();

    expect($debit->fresh()->transfer_pair_id)->toBeNull()
        ->and($debit->fresh()->suggested_pair_id)->toBeNull()
        ->and($credit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Unlinked);
});

test('the user option limits detection to one user', function () {
    $otherUser = User::factory()->create();
    $a = Account::factory()->for($otherUser)->create();
    $b = Account::factory()->for($otherUser)->create();
    feedRow($otherUser, $a, -700, '2026-09-10');
    feedRow($otherUser, $b, 700, '2026-09-10');
    feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    feedRow($this->user, $this->cc, 5000, '2026-09-10');

    $this->artisan('transfers:detect --user='.$this->user->id)->assertSuccessful();

    expect(Transaction::query()->where('user_id', $otherUser->id)->whereNotNull('suggested_pair_id')->count())->toBe(0)
        ->and(Transaction::query()->where('user_id', $this->user->id)->whereNotNull('suggested_pair_id')->count())->toBe(2);
});

test('a remembered rule does not link a pair that has a second possible match on another account', function () {
    TransferRule::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->optimus->id,
        'counterpart_account_id' => $this->cc->id,
        'description_pattern' => 'Optimus to CC',
    ]);
    $debit = feedRow($this->user, $this->optimus, -5000, '2026-09-10', 'Transfer Optimus to CC to SAV');
    feedRow($this->user, $this->cc, 5000, '2026-09-10');
    $elsewhere = Account::factory()->for($this->user)->create();
    feedRow($this->user, $elsewhere, 5000, '2026-09-10');

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($debit->fresh()->transfer_pair_id)->toBeNull()
        ->and($debit->fresh()->transfer_link_source)->not->toBe(TransferLinkSource::Rule);
});

test('the analysis pipeline links transfers before other stages run and suggests only after rules', function () {
    $debit = feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    $credit = feedRow($this->user, $this->cc, 5000, '2026-09-10');

    $run = app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    $before = PipelineAuditEntry::query()->where('pipeline_run_id', $run->id)->where('stage', 'transfer-detection')->where('action', 'transfers_detected')->count();
    $after = PipelineAuditEntry::query()->where('pipeline_run_id', $run->id)->where('stage', 'transfer-detection-after-rules')->pluck('action');

    expect($run->stages_completed[0])->toBe('transfer-detection')
        ->and($debit->fresh()->suggested_pair_id)->toBe($credit->id)
        ->and($before)->toBe(0)
        ->and($after->all())->toContain('transfers_detected')
        ->and($after->all())->not->toContain('transfers_linked');
});

test('a suggested pair still counts until confirmed, then neither leg counts', function () {
    $category = Category::factory()->create(['name' => 'Transfer']);
    $debit = feedRow($this->user, $this->optimus, -252100, '2026-09-10');
    $debit->update(['category_id' => $category->id]);
    $credit = feedRow($this->user, $this->cc, 252100, '2026-09-10');
    feedRow($this->user, $this->optimus, 571860, '2026-09-10', 'Direct Credit PAYROLL');

    $load = fn () => (new DayActivityLoader)->load(
        CarbonImmutable::parse('2026-09-01'),
        CarbonImmutable::parse('2026-09-30'),
        $this->user->id,
    )['2026-09-10']->incomeCents;

    expect($load())->toBe(571860 + 252100);

    $this->artisan('transfers:detect')->assertSuccessful();

    // Suggested only: keeps its current treatment, so the credit leg still counts as income.
    expect($debit->fresh()->suggested_pair_id)->toBe($credit->id)
        ->and($load())->toBe(571860 + 252100)
        ->and(Transaction::query()->where('user_id', $this->user->id)->possibleTransfer()->count())->toBe(2);

    app(TransferLinker::class)->confirm($debit->fresh());

    expect($load())->toBe(571860)
        ->and(Transaction::query()->where('user_id', $this->user->id)->possibleTransfer()->count())->toBe(0);
});

test('a suggested pair is unchanged in report totals until confirmed', function () {
    $debit = feedRow($this->user, $this->optimus, -2000, '2026-09-06');
    $credit = feedRow($this->user, $this->cc, 2000, '2026-09-06');
    $this->artisan('transfers:detect')->assertSuccessful();

    $total = fn (): int => (int) collect(app(ReportAggregator::class)->atoms(
        $this->user,
        'real',
        CarbonImmutable::parse('2026-09-01'),
        CarbonImmutable::parse('2026-09-30'),
    ))->sum('total');

    expect($total())->toBe(4000);

    app(TransferLinker::class)->confirm($credit->fresh());

    expect($total())->toBe(0);
});

test('reports and the eloquent scope agree on what a transfer is', function () {
    $transferCategory = Category::factory()->create(['name' => 'Transfer']);
    $child = Category::factory()->create(['name' => 'Savings', 'parent_id' => $transferCategory->id]);
    $plain = feedRow($this->user, $this->optimus, 1000, '2026-09-05');
    $paired = feedRow($this->user, $this->optimus, 2000, '2026-09-06');
    $pairedLeg = feedRow($this->user, $this->cc, -2000, '2026-09-06');
    app(TransferLinker::class)->link($paired, $pairedLeg, TransferLinkSource::Manual);
    $byCategory = feedRow($this->user, $this->optimus, 3000, '2026-09-07');
    $byCategory->update(['category_id' => $transferCategory->id]);
    $byChildCategory = feedRow($this->user, $this->optimus, 4000, '2026-09-08');
    $byChildCategory->update(['category_id' => $child->id]);

    $scoped = Transaction::query()->where('user_id', $this->user->id)->excludingTransfers()->pluck('id')->all();

    $atoms = app(ReportAggregator::class)->atoms(
        $this->user,
        'real',
        CarbonImmutable::parse('2026-09-01'),
        CarbonImmutable::parse('2026-09-30'),
    );

    expect($scoped)->toBe([$plain->id])
        ->and(collect($atoms)->sum('total'))->toBe(1000);
});

test('rows without a transfer signal are never suggested, however well the amounts match', function () {
    feedRow($this->user, $this->optimus, -4599, '2026-09-10', 'VISA PURCHASE APPLE');
    feedRow($this->user, $this->cc, 4599, '2026-09-11', 'REFUND APPLE');

    $this->artisan('transfers:detect')->assertSuccessful();

    expect(Transaction::query()->whereNotNull('suggested_pair_id')->count())->toBe(0);
});

test('a row categorised as Transfer is a candidate even without the keyword', function () {
    $category = Category::factory()->create(['name' => 'Transfer']);
    $debit = feedRow($this->user, $this->optimus, -252100, '2026-09-10', 'Optmus to CC');
    $debit->update(['category_id' => $category->id]);
    $credit = feedRow($this->user, $this->cc, 252100, '2026-09-10', 'Deposit');
    $credit->update(['category_id' => $category->id]);

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($debit->fresh()->suggested_pair_id)->toBe($credit->id);
});

test('a leg arriving after its partner is suggested on the next run', function () {
    $debit = feedRow($this->user, $this->optimus, -5000, '2026-09-10');

    $this->artisan('transfers:detect')->assertSuccessful();
    expect($debit->fresh()->suggested_pair_id)->toBeNull();

    $credit = feedRow($this->user, $this->cc, 5000, '2026-09-11');
    $this->artisan('transfers:detect')->assertSuccessful();

    expect($debit->fresh()->suggested_pair_id)->toBe($credit->id)
        ->and($credit->fresh()->suggested_pair_id)->toBe($debit->id);
});

test('a dry run does not report a rule pair a second time as a suggestion', function () {
    TransferRule::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->optimus->id,
        'counterpart_account_id' => $this->cc->id,
        'description_pattern' => 'Transfer',
    ]);
    feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    feedRow($this->user, $this->cc, 5000, '2026-09-10');

    $this->artisan('transfers:detect --dry-run')
        ->expectsOutputToContain('Would link 1 rule-based pair(s); would suggest 0 pair(s) for review.')
        ->assertSuccessful();
});

test('rules only link imported rows, never manual ones', function () {
    TransferRule::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->optimus->id,
        'counterpart_account_id' => $this->cc->id,
        'description_pattern' => 'Transfer',
    ]);
    $manualDebit = Transaction::factory()->for($this->user)->manual()->create([
        'account_id' => $this->optimus->id,
        'direction' => TransactionDirection::Debit,
        'amount' => -5000,
        'post_date' => '2026-09-10',
        'description' => 'Transfer typed by hand',
    ]);
    feedRow($this->user, $this->cc, 5000, '2026-09-10');

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($manualDebit->fresh()->transfer_pair_id)->toBeNull();
});

test('linking gives an uncategorised leg the Transfer category and refuses a row linked elsewhere', function () {
    $category = Category::factory()->create(['name' => 'Transfer']);
    $debit = feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    $debit->update(['category_id' => $category->id]);
    $credit = feedRow($this->user, $this->cc, 5000, '2026-09-10');
    $other = feedRow($this->user, $this->cc, 5000, '2026-09-11');
    $linker = app(TransferLinker::class);

    expect($linker->link($debit, $credit, TransferLinkSource::Manual))->toBeTrue()
        ->and($credit->fresh()->category_id)->toBe($category->id)
        ->and($linker->link($debit->fresh(), $other, TransferLinkSource::Manual))->toBeFalse()
        ->and($other->fresh()->transfer_pair_id)->toBeNull()
        ->and($debit->fresh()->transfer_pair_id)->toBe($credit->id);
});

test('the #354/#92 pair: suggested, still income until confirmed, then a rule links the next one', function () {
    $category = Category::factory()->create(['name' => 'Transfer']);
    $debit = feedRow($this->user, $this->optimus, -252100, '2026-09-10', 'Transfer Optimus to CC to SAV xxxx4373 MOBILE#2532147918');
    $debit->update(['category_id' => $category->id]);
    $credit = feedRow($this->user, $this->cc, 252100, '2026-09-10', 'Transfer Optimus to CC from SAV xxxx5066 MOBILE#2532147918');
    feedRow($this->user, $this->optimus, 571860, '2026-09-10', 'Direct Credit WINABLE PAYROLL');

    $income = fn (): int => (new DayActivityLoader)->load(
        CarbonImmutable::parse('2026-09-01'),
        CarbonImmutable::parse('2026-09-30'),
        $this->user->id,
    )['2026-09-10']->incomeCents;

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($debit->fresh()->suggested_pair_id)->toBe($credit->id)
        ->and($income())->toBe(823960);

    app(TransferLinker::class)->confirm($debit->fresh());

    expect($income())->toBe(571860)
        ->and($credit->fresh()->category_id)->toBe($category->id);

    $nextDebit = feedRow($this->user, $this->optimus, -300000, '2026-10-10', 'Transfer Optimus to CC to SAV xxxx4373 MOBILE#2999999999');
    $nextCredit = feedRow($this->user, $this->cc, 300000, '2026-10-10', 'Transfer Optimus to CC from SAV xxxx5066 MOBILE#2999999999');

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($nextDebit->fresh()->transfer_pair_id)->toBe($nextCredit->id)
        ->and($nextDebit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Rule);
});

test('a rule pattern keeps the stable head of the description', function () {
    expect(TransferRule::patternFor('Transfer Optimus to CC from SAV xxxx5066 MOBILE#2532147918'))
        ->toBe('Transfer Optimus to CC from SAV xxxx5066')
        ->and(TransferRule::patternFor('Round Up transfer to xxxx4599: VISA'))->toBe('Round Up transfer to xxxx4599: VISA')
        ->and(TransferRule::patternFor('12345678 payroll'))->toBe('12345678 payroll');
});

test('linking to an untracked account refuses a row that was linked elsewhere and creates no mirror', function () {
    $hidden = Account::factory()->for($this->user)->untracked()->create();
    $debit = feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    $credit = feedRow($this->user, $this->cc, 5000, '2026-09-10');
    $linker = app(TransferLinker::class);
    $linker->link($debit, $credit, TransferLinkSource::Manual);
    $before = Transaction::query()->count();

    expect(fn () => $linker->linkToUntrackedAccount($debit->fresh(), $hidden, TransferLinkSource::Manual))
        ->toThrow(TransferAlreadyLinkedException::class)
        ->and(Transaction::query()->count())->toBe($before)
        ->and($debit->fresh()->transfer_pair_id)->toBe($credit->id);
});

test('overlapping rules that point at different counterparts are ambiguous and link nothing', function () {
    $other = Account::factory()->for($this->user)->create(['name' => 'Savings']);
    foreach ([$this->cc, $other] as $counterpart) {
        TransferRule::query()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->optimus->id,
            'counterpart_account_id' => $counterpart->id,
            'description_pattern' => 'Transfer',
        ]);
    }
    feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    feedRow($this->user, $this->cc, 5000, '2026-09-10');
    feedRow($this->user, $other, 5000, '2026-09-10');

    $this->artisan('transfers:detect')->assertSuccessful();

    expect(Transaction::query()->whereNotNull('transfer_pair_id')->count())->toBe(0);
});

test('confirming a pair claimed elsewhere writes no rules and reports failure', function () {
    $debit = feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    $credit = feedRow($this->user, $this->cc, 5000, '2026-09-10');
    $rival = feedRow($this->user, $this->optimus, -5000, '2026-09-11');
    $this->artisan('transfers:detect')->assertSuccessful();
    $linker = app(TransferLinker::class);

    // The credit is claimed by another debit between suggestion and confirmation.
    $stale = $debit->fresh();
    expect($linker->link($rival, $credit->fresh(), TransferLinkSource::Manual))->toBeTrue();

    expect($linker->confirm($stale))->toBeFalse()
        ->and(TransferRule::query()->count())->toBe(0)
        ->and($debit->fresh()->transfer_pair_id)->toBeNull();
});

test('unlinking a transfer whose legs are categorised Transfer makes them count again', function () {
    $category = Category::factory()->create(['name' => 'Transfer']);
    $debit = feedRow($this->user, $this->optimus, -252100, '2026-09-10');
    $debit->update(['category_id' => $category->id]);
    $credit = feedRow($this->user, $this->cc, 252100, '2026-09-10');
    $linker = app(TransferLinker::class);
    $linker->link($debit, $credit, TransferLinkSource::Manual);
    $excluded = fn (): int => Transaction::query()->where('user_id', $this->user->id)->excludingTransfers()->count();
    $reported = fn (): int => (int) collect(app(ReportAggregator::class)->atoms(
        $this->user,
        'real',
        CarbonImmutable::parse('2026-09-01'),
        CarbonImmutable::parse('2026-09-30'),
    ))->sum('total');

    expect($excluded())->toBe(0)->and($reported())->toBe(0);

    $linker->unlink($debit->fresh());

    // Both legs kept the inherited Transfer category, but the explicit decision wins.
    expect($excluded())->toBe(2)->and($reported())->toBe(504200);
});

test('a suggestion rejected as not a transfer counts normally even when categorised Transfer', function () {
    $category = Category::factory()->create(['name' => 'Transfer']);
    $debit = feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    $debit->update(['category_id' => $category->id]);
    feedRow($this->user, $this->cc, 5000, '2026-09-10');
    $this->artisan('transfers:detect')->assertSuccessful();

    expect(Transaction::query()->where('user_id', $this->user->id)->excludingTransfers()->count())->toBe(1);

    app(TransferLinker::class)->markNotTransfer($debit->fresh());

    expect(Transaction::query()->where('user_id', $this->user->id)->excludingTransfers()->count())->toBe(2);
});

test('a rule pointing at a hidden account does not create a mirror next to a real opposite row', function () {
    $hidden = Account::factory()->for($this->user)->untracked()->create(['name' => 'Pot']);
    TransferRule::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->optimus->id,
        'counterpart_account_id' => $hidden->id,
        'description_pattern' => 'Transfer',
    ]);
    feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    feedRow($this->user, $this->cc, 5000, '2026-09-10');

    $this->artisan('transfers:detect')->assertSuccessful();

    expect(Transaction::query()->where('account_id', $hidden->id)->count())->toBe(0);
});

test('the command tells suggested pairs apart from linked ones', function () {
    feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    feedRow($this->user, $this->cc, 5000, '2026-09-10');

    $this->artisan('transfers:detect')
        ->expectsOutputToContain('suggested user')
        ->doesntExpectOutputToContain('linked user')
        ->assertSuccessful();
});

test('a confirm that lost the race to Not a transfer does not overwrite it', function () {
    $debit = feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    $credit = feedRow($this->user, $this->cc, 5000, '2026-09-10');
    $this->artisan('transfers:detect')->assertSuccessful();
    $linker = app(TransferLinker::class);
    $stale = $debit->fresh();

    $linker->markNotTransfer($debit->fresh());

    expect($linker->confirm($stale))->toBeFalse()
        ->and($debit->fresh()->transfer_pair_id)->toBeNull()
        ->and($debit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Unlinked)
        ->and($credit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Unlinked)
        ->and(TransferRule::query()->count())->toBe(0);
});

test('a Not a transfer that lost the race to confirm leaves the confirmed pair and its rules alone', function () {
    $debit = feedRow($this->user, $this->optimus, -5000, '2026-09-10', 'Transfer to xx1234');
    $credit = feedRow($this->user, $this->cc, 5000, '2026-09-10', 'Transfer to xx1234');
    $this->artisan('transfers:detect')->assertSuccessful();
    $linker = app(TransferLinker::class);
    $stale = $debit->fresh();

    expect($linker->confirm($debit->fresh()))->toBeTrue();
    $linker->markNotTransfer($stale);

    expect($debit->fresh()->transfer_pair_id)->toBe($credit->id)
        ->and($debit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Confirmed)
        ->and($credit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Confirmed)
        ->and(TransferRule::query()->count())->toBe(2);
});

test('a dry run skips a hidden-account rule row that has a real opposite row, like the real run', function () {
    $hidden = Account::factory()->for($this->user)->untracked()->create(['name' => 'Pot']);
    TransferRule::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->optimus->id,
        'counterpart_account_id' => $hidden->id,
        'description_pattern' => 'Transfer',
    ]);
    feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    feedRow($this->user, $this->cc, 5000, '2026-09-10');

    $this->artisan('transfers:detect --dry-run')
        ->expectsOutputToContain('Would link 0 rule-based pair(s)')
        ->assertSuccessful();
});

test('versioning a leg never resurrects a suggestion rejected in the meantime', function () {
    $debit = feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    $credit = feedRow($this->user, $this->cc, 5000, '2026-09-10');
    $this->artisan('transfers:detect')->assertSuccessful();
    $linker = app(TransferLinker::class);

    // A modal opened on the suggested row, then the pair was rejected before it saved.
    $stale = $debit->fresh();
    $linker->markNotTransfer($credit->fresh());
    $child = $stale->createChild(['notes' => 'edited']);

    $linker->followVersion($stale, $child);

    expect($child->fresh()->suggested_pair_id)->toBeNull()
        ->and($child->fresh()->transfer_link_source)->toBe(TransferLinkSource::Unlinked)
        ->and($credit->fresh()->suggested_pair_id)->toBeNull();
});

test('versioning a leg repoints a suggestion that still holds', function () {
    $debit = feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    $credit = feedRow($this->user, $this->cc, 5000, '2026-09-10');
    $this->artisan('transfers:detect')->assertSuccessful();
    $linker = app(TransferLinker::class);

    $stale = $debit->fresh();
    $child = $stale->createChild(['notes' => 'edited']);
    $linker->followVersion($stale, $child);

    expect($credit->fresh()->suggested_pair_id)->toBe($child->id)
        ->and($child->fresh()->suggested_pair_id)->toBe($credit->id);
});

test('rule links keep the mutual single match: two same-amount source rows for one counterpart link nothing', function () {
    TransferRule::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->optimus->id,
        'counterpart_account_id' => $this->cc->id,
        'description_pattern' => 'Transfer',
    ]);
    feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    feedRow($this->user, $this->optimus, -5000, '2026-09-11');
    feedRow($this->user, $this->cc, 5000, '2026-09-10');

    $this->artisan('transfers:detect')->assertSuccessful();

    expect(Transaction::query()->whereNotNull('transfer_pair_id')->count())->toBe(0);
});

test('confirm mints rules only for a reviewed pair, never for a manual or rule link', function () {
    $debit = feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    $credit = feedRow($this->user, $this->cc, 5000, '2026-09-10');
    $linker = app(TransferLinker::class);
    $linker->link($debit, $credit, TransferLinkSource::Manual);

    expect($linker->confirm($debit->fresh()))->toBeFalse()
        ->and(TransferRule::query()->count())->toBe(0)
        ->and($debit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Manual);
});

test('a stale unlink does not touch a row that was linked elsewhere in the meantime', function () {
    $a = feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    $b = feedRow($this->user, $this->cc, 5000, '2026-09-10');
    $c = feedRow($this->user, $this->cc, 5000, '2026-09-11');
    $linker = app(TransferLinker::class);
    $linker->link($a, $b, TransferLinkSource::Manual);
    $stale = $a->fresh();

    $linker->unlink($a->fresh());
    $linker->link($a->fresh(), $c->fresh(), TransferLinkSource::Manual);
    $linker->unlink($stale);

    expect($a->fresh()->transfer_pair_id)->toBe($c->id)
        ->and($c->fresh()->transfer_pair_id)->toBe($a->id)
        ->and($a->fresh()->transfer_link_source)->toBe(TransferLinkSource::Manual);
});

test('detection loads only imported rows that carry a transfer signal', function () {
    foreach (range(1, 25) as $i) {
        feedRow($this->user, $this->optimus, -1000 - $i, '2026-09-10', 'Coffee '.$i);
    }
    Transaction::factory()->for($this->user)->manual()->count(10)->create(['account_id' => $this->optimus->id]);
    feedRow($this->user, $this->optimus, -252100, '2026-09-10');
    feedRow($this->user, $this->cc, 252100, '2026-09-10');

    $armed = new stdClass;
    $armed->on = true;
    $armed->retrieved = 0;
    Transaction::retrieved(function () use ($armed): void {
        if ($armed->on) {
            $armed->retrieved++;
        }
    });

    try {
        $this->artisan('transfers:detect')->assertSuccessful();
    } finally {
        $armed->on = false;
    }

    // 2 signal rows plus the locked re-reads inside suggest(); never the 35 unrelated rows.
    expect($armed->retrieved)->toBeLessThanOrEqual(8)
        ->and(Transaction::query()->whereNotNull('suggested_pair_id')->count())->toBe(2);
});

test('csv rows are not suggested: detection works on bank-feed rows only', function () {
    Transaction::factory()->for($this->user)->fromCsv()->create([
        'account_id' => $this->optimus->id,
        'direction' => TransactionDirection::Debit,
        'amount' => -5000,
        'post_date' => '2026-09-10',
        'description' => 'Transfer',
    ]);
    Transaction::factory()->for($this->user)->fromCsv()->create([
        'account_id' => $this->cc->id,
        'direction' => TransactionDirection::Credit,
        'amount' => 5000,
        'post_date' => '2026-09-10',
        'description' => 'Transfer',
    ]);

    $this->artisan('transfers:detect')->assertSuccessful();

    expect(Transaction::query()->whereNotNull('suggested_pair_id')->count())->toBe(0);
});

test('a version superseded by an edit is refused, the current version links', function () {
    $debit = feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    $credit = feedRow($this->user, $this->cc, 5000, '2026-09-10');
    $linker = app(TransferLinker::class);

    // The caller picked $debit, then an edit created a newer version of it.
    $current = $debit->createChild(['notes' => 'edited']);

    expect($linker->link($debit, $credit, TransferLinkSource::Manual))->toBeFalse()
        ->and($linker->suggest($debit, $credit))->toBeFalse()
        ->and($credit->fresh()->transfer_pair_id)->toBeNull()
        ->and($debit->fresh()->transfer_pair_id)->toBeNull()
        ->and($linker->link($current, $credit, TransferLinkSource::Manual))->toBeTrue()
        ->and($credit->fresh()->transfer_pair_id)->toBe($current->id);
});

test('Not a transfer never undoes a pair that was confirmed elsewhere', function () {
    $debit = feedRow($this->user, $this->optimus, -5000, '2026-09-10', 'Transfer to xx1234');
    $credit = feedRow($this->user, $this->cc, 5000, '2026-09-10', 'Transfer to xx1234');
    $this->artisan('transfers:detect')->assertSuccessful();
    $linker = app(TransferLinker::class);

    $linker->confirm($debit->fresh());
    // A second tab reloads the row after the confirmation: no suggestion pointer, but a link.
    $linker->markNotTransfer($debit->fresh());

    expect($debit->fresh()->transfer_pair_id)->toBe($credit->id)
        ->and($debit->fresh()->transfer_link_source)->toBe(TransferLinkSource::Confirmed)
        ->and(TransferRule::query()->count())->toBe(2);
});

test('a stale untracked account model cannot receive a mirror once it has been tracked', function () {
    $hidden = Account::factory()->for($this->user)->untracked()->create(['name' => 'uBank']);
    $stale = Account::query()->findOrFail($hidden->id);
    $row = feedRow($this->user, $this->optimus, -5000, '2026-09-10');

    Account::query()->whereKey($hidden->id)->update(['is_tracked' => true]);
    $before = Transaction::query()->count();

    expect(fn () => app(TransferLinker::class)->linkToUntrackedAccount($row, $stale, TransferLinkSource::Manual))
        ->toThrow(AccountNotUntrackedException::class)
        ->and(Transaction::query()->count())->toBe($before)
        ->and($row->fresh()->transfer_pair_id)->toBeNull();
});

test('rejecting an unmatched row stamps its current version when an edit versioned it meanwhile', function () {
    $row = feedRow($this->user, $this->optimus, -5000, '2026-09-10', 'Transfer to nowhere');
    $stale = $row->fresh();
    $child = $row->createChild(['notes' => 'edited meanwhile']);

    app(TransferLinker::class)->markNotTransfer($stale);

    expect($child->fresh()->transfer_link_source)->toBe(TransferLinkSource::Unlinked)
        ->and(app(TransferReviewQueue::class)->unmatched($this->user)->count())->toBe(0);
});

test('a version built from stale attributes keeps a rejection made after the modal opened', function () {
    $row = feedRow($this->user, $this->optimus, -5000, '2026-09-10', 'Transfer to nowhere');
    $stale = $row->fresh();
    app(TransferLinker::class)->markNotTransfer($row->fresh());
    $child = $stale->createChild(['notes' => 'edited after reject']);

    app(TransferLinker::class)->followVersion($stale, $child);

    expect($child->fresh()->transfer_link_source)->toBe(TransferLinkSource::Unlinked)
        ->and(app(TransferReviewQueue::class)->unmatched($this->user)->count())->toBe(0);
});

test('a confirmed pair confirmed again is a no-op that does not recreate a deleted rule', function () {
    $debit = feedRow($this->user, $this->optimus, -5000, '2026-09-10', 'Transfer to xx1234');
    feedRow($this->user, $this->cc, 5000, '2026-09-10', 'Transfer to xx1234');
    $this->artisan('transfers:detect')->assertSuccessful();
    $linker = app(TransferLinker::class);

    expect($linker->confirm($debit->fresh()))->toBeTrue();
    TransferRule::query()->where('account_id', $this->optimus->id)->delete();

    expect($linker->confirm($debit->fresh()))->toBeTrue()
        ->and(TransferRule::query()->where('account_id', $this->optimus->id)->count())->toBe(0)
        ->and(TransferRule::query()->count())->toBe(1);
});

test('a row filed under Transfer by a category rule is detected in the same pipeline run', function () {
    $category = Category::factory()->create(['name' => 'Transfer']);
    $group = App\Models\UserRuleGroup::factory()->for($this->user)->create();
    App\Models\UserRule::factory()->create([
        'user_id' => $this->user->id,
        'user_rule_group_id' => $group->id,
        'triggers' => [['field' => 'description', 'operator' => 'contains', 'value' => 'CCXFER']],
        'actions' => [['type' => 'set_category', 'value' => (string) $category->id]],
        'is_auto_apply' => true,
    ]);
    // Neither description mentions "transfer": only the category the rule assigns makes them candidates.
    $debit = feedRow($this->user, $this->optimus, -252100, '2026-09-10', 'CCXFER OUT');
    $credit = feedRow($this->user, $this->cc, 252100, '2026-09-10', 'CCXFER IN');

    $run = app(TransactionAnalysisPipeline::class)->run($this->user, PipelineTrigger::Sync);

    expect($run->stages_completed)->toContain('transfer-detection', 'transfer-detection-after-rules')
        ->and($debit->fresh()->category_id)->toBe($category->id)
        ->and($debit->fresh()->suggested_pair_id)->toBe($credit->id)
        ->and($credit->fresh()->suggested_pair_id)->toBe($debit->id);
});

test('staggered Round Ups across two syncs never get a second suggestion', function () {
    $d1 = feedRow($this->user, $this->optimus, -150, '2026-09-10', 'Round Up transfer to xxxx4599');
    $c1 = feedRow($this->user, $this->cc, 150, '2026-09-10', 'Round Up transfer to xxxx4599');

    $this->artisan('transfers:detect')->assertSuccessful();
    expect($d1->fresh()->suggested_pair_id)->toBe($c1->id);

    $d2 = feedRow($this->user, $this->optimus, -150, '2026-09-11', 'Round Up transfer to xxxx4599');
    $c2 = feedRow($this->user, $this->cc, 150, '2026-09-11', 'Round Up transfer to xxxx4599');

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($d2->fresh()->suggested_pair_id)->toBeNull()
        ->and($c2->fresh()->suggested_pair_id)->toBeNull()
        ->and($d1->fresh()->suggested_pair_id)->toBe($c1->id);
});

test('a rule pass links each unambiguous pair once and a second run adds nothing', function () {
    $sav = Account::factory()->for($this->user)->create(['name' => 'SAV']);
    foreach ([[$this->cc, 'Optimus to CC'], [$sav, 'Optimus to SAV']] as [$counterpart, $pattern]) {
        TransferRule::query()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->optimus->id,
            'counterpart_account_id' => $counterpart->id,
            'description_pattern' => $pattern,
        ]);
    }
    $o1 = feedRow($this->user, $this->optimus, -10000, '2026-09-10', 'Transfer Optimus to CC');
    $o2 = feedRow($this->user, $this->optimus, -10000, '2026-09-20', 'Transfer Optimus to SAV');
    $c1 = feedRow($this->user, $this->cc, 10000, '2026-09-11', 'Deposit');
    $s1 = feedRow($this->user, $sav, 10000, '2026-09-21', 'Deposit');

    $first = app(TransferDetector::class)->runRules($this->user);
    $second = app(TransferDetector::class)->runRules($this->user);

    expect($first['rule'])->toBe(2)
        ->and($second['rule'])->toBe(0)
        ->and($o2->fresh()->transfer_pair_id)->toBe($s1->id)
        ->and($o1->fresh()->transfer_pair_id)->toBe($c1->id);
});

test('a rule head made only of generic words is never remembered, a masked account token is kept', function () {
    expect(TransferRule::patternFor('Transfer to'))->toBe('')
        ->and(TransferRule::patternFor('TRANSFER 123456789'))->toBe('')
        ->and(TransferRule::patternFor('Transfer to xx1234 CommBank app'))->toBe('Transfer to xx1234 CommBank app')
        ->and(TransferRule::patternFor('Transfer from xxxx5066 MOBILE#2532147918'))->toBe('Transfer from xxxx5066');
});

test('a suggestion whose partner was soft-deleted is cleared and leaves the queue', function () {
    $debit = feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    $credit = feedRow($this->user, $this->cc, 5000, '2026-09-10');
    $this->artisan('transfers:detect')->assertSuccessful();

    $credit->delete();

    expect($debit->fresh()->suggested_pair_id)->toBeNull()
        ->and(app(TransferReviewQueue::class)->suggestedPairs($this->user)->count())->toBe(0);
});

test('Not a transfer reports nothing written for a row that is already linked', function () {
    $debit = feedRow($this->user, $this->optimus, -5000, '2026-09-10');
    $credit = feedRow($this->user, $this->cc, 5000, '2026-09-10');
    $linker = app(TransferLinker::class);
    $linker->link($debit, $credit, TransferLinkSource::Manual);

    expect($linker->markNotTransfer($debit->fresh()))->toBeFalse();
});

test('a dry run agrees with the real run when a rule pair claims the real opposite row of a hidden-account rule row', function () {
    $hidden = Account::factory()->for($this->user)->untracked()->create(['name' => 'Spaceship']);
    $everyday = Account::factory()->for($this->user)->create(['name' => 'Everyday']);
    TransferRule::query()->create([
        'user_id' => $this->user->id, 'account_id' => $this->optimus->id,
        'counterpart_account_id' => $this->cc->id, 'description_pattern' => 'Transfer Optimus to CC',
    ]);
    TransferRule::query()->create([
        'user_id' => $this->user->id, 'account_id' => $everyday->id,
        'counterpart_account_id' => $hidden->id, 'description_pattern' => 'Transfer to Spaceship',
    ]);
    feedRow($this->user, $this->optimus, -10000, '2026-09-10', 'Transfer Optimus to CC');
    feedRow($this->user, $this->cc, 10000, '2026-09-10', 'Deposit');
    feedRow($this->user, $everyday, -10000, '2026-09-10', 'Transfer to Spaceship');

    $dry = app(TransferDetector::class)->runRules($this->user, dryRun: true);
    $real = app(TransferDetector::class)->runRules($this->user);

    expect($dry['rule'])->toBe($real['rule']);
});
