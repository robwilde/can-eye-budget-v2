<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Enums\TransactionDirection;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Livewire\OccurrencePaymentPanel;
use App\Livewire\RecurringTransactionReview;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\AuditEvent;
use App\Models\PipelineRun;
use App\Models\PlannedMatchRejection;
use App\Models\PlannedOccurrence;
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Recurring\PlannedPaymentMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-03-17 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->user->update(['primary_account_id' => $this->account->id]);
    $this->run = PipelineRun::factory()->for($this->user)->create();
});

function reviewWorld(object $test, float $score = 0.7, array $transaction = [], ?User $owner = null, ?Account $account = null): array
{
    $owner ??= $test->user;
    $account ??= $test->account;
    $tx = Transaction::factory()->for($owner)->for($account)->create([
        'description' => 'ACME POWER BILL', 'amount' => -10400, 'direction' => TransactionDirection::Debit, 'post_date' => '2026-03-16', 'planned_transaction_id' => null, ...$transaction,
    ]);
    $plan = PlannedTransaction::factory()->for($owner)->for($account)->create([
        'description' => 'Acme Power', 'amount' => 10000, 'direction' => TransactionDirection::Debit, 'merchant_key' => null,
        'frequency' => App\Enums\RecurrenceFrequency::EveryMonth, 'start_date' => '2026-01-10', 'until_date' => null,
    ]);
    $occurrence = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-03-10', 'due_date' => '2026-03-10']);
    $suggestion = AnalysisSuggestion::query()->create([
        'user_id' => $owner->id, 'pipeline_run_id' => PipelineRun::factory()->for($owner)->create()->id, 'type' => SuggestionType::PlannedPaymentMatch, 'status' => SuggestionStatus::Pending,
        'payload' => ['transaction_id' => $tx->id, 'planned_transaction_id' => $plan->id, 'planned_occurrence_id' => $occurrence->id, 'account_id' => $account->id, 'score' => $score, 'signals' => ['identity' => 0.35, 'amount' => 0.2, 'date' => 0.05, 'account' => 0.1]],
    ]);

    return [$suggestion, $tx, $plan, $occurrence];
}

function reviewPage(object $test)
{
    return Livewire::actingAs($test->user)->test(RecurringTransactionReview::class);
}

it('orders suggested payments by confidence', function () {
    [$low] = reviewWorld($this, 0.62);
    [$high] = reviewWorld($this, 0.80);

    reviewPage($this)->assertSeeHtmlInOrder([
        'data-testid="payment-suggestion-'.$high->id.'"', '80% confidence', 'data-testid="payment-suggestion-signals"', 'Name 0.35', 'Amount 0.20', 'Date 0.05', 'Account 0.10',
        'data-testid="payment-suggestion-'.$low->id.'"', '62% confidence',
    ]);
});

it('turns a confirmed suggestion into a payment', function () {
    [$suggestion, $tx, $plan, $occurrence] = reviewWorld($this);

    reviewPage($this)->call('confirmPlannedPayment', $suggestion->id)->assertHasNoErrors();

    expect($suggestion->fresh()->status)->toBe(SuggestionStatus::Accepted)
        ->and($suggestion->fresh()->resolved_at)->not->toBeNull()
        ->and(PlannedPayment::query()->sole()->transaction_id)->toBe($tx->id)
        ->and($tx->fresh()->planned_transaction_id)->toBe($plan->id)
        ->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Paid);
});

it('records a rejection so the pair is never suggested again', function () {
    $tx = Transaction::factory()->for($this->user)->for($this->account)->create(['description' => 'ACME POWER BILL SYDNEY', 'amount' => -10400, 'direction' => TransactionDirection::Debit, 'post_date' => '2026-03-17', 'planned_transaction_id' => null]);
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create(['description' => 'Acme Power', 'amount' => 10000, 'direction' => TransactionDirection::Debit, 'merchant_key' => null, 'frequency' => App\Enums\RecurrenceFrequency::EveryMonth, 'start_date' => '2026-01-10']);
    PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-03-10', 'due_date' => '2026-03-10']);

    app(PlannedPaymentMatcher::class)->matchForUser($this->user, $this->run);
    $suggestion = AnalysisSuggestion::query()->ofType(SuggestionType::PlannedPaymentMatch)->sole();

    reviewPage($this)->call('rejectPlannedPayment', $suggestion->id)->assertHasNoErrors();

    expect($suggestion->fresh()->status)->toBe(SuggestionStatus::Rejected)
        ->and(PlannedMatchRejection::query()->where(['user_id' => $this->user->id, 'planned_transaction_id' => $plan->id, 'transaction_id' => $tx->id])->count())->toBe(1);

    app(PlannedPaymentMatcher::class)->matchForUser($this->user, PipelineRun::factory()->for($this->user)->create());

    expect(AnalysisSuggestion::query()->ofType(SuggestionType::PlannedPaymentMatch)->pending()->count())->toBe(0)->and($tx->fresh()->planned_transaction_id)->toBeNull();
});

it('undoes an automatic link from the payment panel', function () {
    $tx = Transaction::factory()->for($this->user)->for($this->account)->create(['description' => 'ACME POWER', 'amount' => -10000, 'direction' => TransactionDirection::Debit, 'post_date' => '2026-03-12', 'planned_transaction_id' => null]);
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create(['description' => 'Acme Power', 'amount' => 10000, 'direction' => TransactionDirection::Debit, 'merchant_key' => $tx->merchant_key, 'frequency' => App\Enums\RecurrenceFrequency::EveryMonth, 'start_date' => '2026-01-10']);
    $occurrence = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-03-10', 'due_date' => '2026-03-10']);

    expect(app(PlannedPaymentMatcher::class)->matchForUser($this->user, $this->run)['linked'])->toBe(1)->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Paid);

    Livewire::actingAs($this->user)->test(OccurrencePaymentPanel::class)->call('open', $occurrence->id)
        ->assertSeeHtml('data-testid="payment-unlink"')
        ->call('unlinkPayment', PlannedPayment::query()->sole()->id)->assertHasNoErrors();

    expect(PlannedPayment::query()->count())->toBe(0)
        ->and($tx->fresh()->planned_transaction_id)->toBeNull()
        ->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled)
        ->and(PlannedMatchRejection::query()->where(['planned_transaction_id' => $plan->id, 'transaction_id' => $tx->id])->count())->toBe(1);

    app(PlannedPaymentMatcher::class)->matchForUser($this->user, PipelineRun::factory()->for($this->user)->create());

    expect($tx->fresh()->planned_transaction_id)->toBeNull();
});

it('refuses to confirm another users suggestion', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->for($other)->create();
    [$theirs, $tx, , $occurrence] = reviewWorld($this, 0.7, [], $other, $otherAccount);

    reviewPage($this)->call('confirmPlannedPayment', $theirs->id)->assertNotFound();
    reviewPage($this)->call('rejectPlannedPayment', $theirs->id)->assertNotFound();

    expect($theirs->fresh()->status)->toBe(SuggestionStatus::Pending)
        ->and(PlannedPayment::query()->count())->toBe(0)
        ->and(PlannedMatchRejection::query()->count())->toBe(0)
        ->and($tx->fresh()->planned_transaction_id)->toBeNull()
        ->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('audits confirming a suggested payment', function () {
    [$suggestion] = reviewWorld($this);

    reviewPage($this)->call('confirmPlannedPayment', $suggestion->id);

    $event = AuditEvent::query()->where('action', 'livewire.recurring-transaction-review.confirmPlannedPayment')->sole();

    expect($event->user_id)->toBe($this->user->id);
});

it('audits rejecting a suggested payment', function () {
    [$suggestion] = reviewWorld($this);

    reviewPage($this)->call('rejectPlannedPayment', $suggestion->id);

    expect(AuditEvent::query()->where('action', 'livewire.recurring-transaction-review.rejectPlannedPayment')->count())->toBe(1);
});

it('audits unlinking a payment', function () {
    [, $tx, $plan, $occurrence] = reviewWorld($this);
    $tx->update(['planned_transaction_id' => $plan->id]);
    app(App\Services\Recurring\OccurrencePaymentRecorder::class)->attach($this->user, $tx->id, $occurrence->id);

    Livewire::actingAs($this->user)->test(OccurrencePaymentPanel::class)->call('open', $occurrence->id)->call('unlinkPayment', PlannedPayment::query()->sole()->id);

    expect(AuditEvent::query()->where('action', 'livewire.occurrence-payment-panel.unlinkPayment')->sole()->user_id)->toBe($this->user->id);
});

it('leaves the suggestion pending and the transaction unlinked when the payment is refused', function () {
    [$suggestion, $tx] = reviewWorld($this, 0.7, ['currency' => 'USD']);

    reviewPage($this)->call('confirmPlannedPayment', $suggestion->id)->assertHasErrors('plannedPayment')->assertSeeHtml('data-testid="planned-payment-error"');

    expect($suggestion->fresh()->status)->toBe(SuggestionStatus::Pending)->and($tx->fresh()->planned_transaction_id)->toBeNull()->and(PlannedPayment::query()->count())->toBe(0);
});

it('cannot confirm the same suggestion twice and supersedes the others for that transaction', function () {
    [$suggestion, $tx, $plan] = reviewWorld($this);
    $second = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-04-10', 'due_date' => '2026-04-10']);
    $other = AnalysisSuggestion::query()->create(['user_id' => $this->user->id, 'pipeline_run_id' => $this->run->id, 'type' => SuggestionType::PlannedPaymentMatch, 'status' => SuggestionStatus::Pending, 'payload' => ['transaction_id' => $tx->id, 'planned_transaction_id' => $plan->id, 'planned_occurrence_id' => $second->id, 'account_id' => $this->account->id, 'score' => 0.6]]);

    reviewPage($this)->call('confirmPlannedPayment', $suggestion->id)->assertHasNoErrors();

    expect($other->fresh()->status)->toBe(SuggestionStatus::Superseded);

    reviewPage($this)->call('confirmPlannedPayment', $suggestion->id)->assertHasErrors('plannedPayment');

    expect(PlannedPayment::query()->count())->toBe(1);
});

it('lists the suggestions of the selected account only', function () {
    $second = Account::factory()->for($this->user)->create();
    [$here] = reviewWorld($this);
    [$there] = reviewWorld($this, 0.7, [], null, $second);

    reviewPage($this)->assertSeeHtml('data-testid="payment-suggestion-'.$here->id.'"')->assertDontSeeHtml('data-testid="payment-suggestion-'.$there->id.'"')
        ->set('accountId', $second->id)->assertSeeHtml('data-testid="payment-suggestion-'.$there->id.'"')->assertDontSeeHtml('data-testid="payment-suggestion-'.$here->id.'"');
});

it('shows no suggestion card when there are none', function () {
    reviewPage($this)->assertDontSeeHtml('data-testid="payment-suggestions"');
});

it('writes the account and the signals into a suggestion', function () {
    Transaction::factory()->for($this->user)->for($this->account)->create(['description' => 'ACME POWER BILL SYDNEY', 'amount' => -10400, 'direction' => TransactionDirection::Debit, 'post_date' => '2026-03-17', 'planned_transaction_id' => null]);
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create(['description' => 'Acme Power', 'amount' => 10000, 'direction' => TransactionDirection::Debit, 'merchant_key' => null, 'frequency' => App\Enums\RecurrenceFrequency::EveryMonth, 'start_date' => '2026-01-10']);
    PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-03-10', 'due_date' => '2026-03-10']);

    app(PlannedPaymentMatcher::class)->matchForUser($this->user, $this->run);

    expect(AnalysisSuggestion::query()->ofType(SuggestionType::PlannedPaymentMatch)->sole()->payload)->toMatchArray(['account_id' => $this->account->id])->toHaveKey('signals.identity');
});

it('leaves out suggestions that can no longer be confirmed', function () {
    [$linked, $linkedTx, $plan] = reviewWorld($this);
    $linkedTx->update(['planned_transaction_id' => $plan->id]);
    [$closed, , , $closedOccurrence] = reviewWorld($this);
    $closedOccurrence->update(['status' => OccurrenceStatus::Skipped, 'closed_at' => now(), 'closed_manually' => true]);
    [$paid, $paidTx, , $paidOccurrence] = reviewWorld($this);
    PlannedPayment::factory()->create(['planned_occurrence_id' => PlannedOccurrence::factory()->for($paidOccurrence->plannedTransaction, 'plannedTransaction')->create(['original_date' => '2026-02-10', 'due_date' => '2026-02-10'])->id, 'transaction_id' => $paidTx->id, 'amount' => 1000]);
    [$live] = reviewWorld($this);

    reviewPage($this)->assertSeeHtml('data-testid="payment-suggestion-'.$live->id.'"')
        ->assertDontSeeHtml('data-testid="payment-suggestion-'.$linked->id.'"')
        ->assertDontSeeHtml('data-testid="payment-suggestion-'.$closed->id.'"')
        ->assertDontSeeHtml('data-testid="payment-suggestion-'.$paid->id.'"');
});

it('refuses to confirm when the transaction has since paid another bill', function () {
    [$suggestion, $tx, , $occurrence] = reviewWorld($this);
    [, , , $other] = reviewWorld($this);
    app(App\Services\Recurring\OccurrencePaymentRecorder::class)->attach($this->user, $tx->id, $other->id, 3000);

    reviewPage($this)->call('confirmPlannedPayment', $suggestion->id)->assertHasErrors('plannedPayment');

    expect(PlannedPayment::query()->sole()->planned_occurrence_id)->toBe($other->id)
        ->and($suggestion->fresh()->status)->toBe(SuggestionStatus::Pending)
        ->and($occurrence->fresh()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('refuses to confirm when the transaction is linked to another plan', function () {
    [$suggestion, $tx] = reviewWorld($this);
    $elsewhere = PlannedTransaction::factory()->for($this->user)->for($this->account)->create();
    $tx->update(['planned_transaction_id' => $elsewhere->id]);

    reviewPage($this)->call('confirmPlannedPayment', $suggestion->id)->assertHasErrors('plannedPayment');

    expect($tx->fresh()->planned_transaction_id)->toBe($elsewhere->id)->and(PlannedPayment::query()->count())->toBe(0);
});

it('says the suggestion changed instead of failing when it was replaced meanwhile', function () {
    [$suggestion] = reviewWorld($this);
    $suggestion->update(['status' => SuggestionStatus::Superseded]);

    reviewPage($this)->call('confirmPlannedPayment', $suggestion->id)->assertOk()->assertHasErrors('plannedPayment');
    reviewPage($this)->call('rejectPlannedPayment', $suggestion->id)->assertOk()->assertHasErrors('plannedPayment');

    expect(PlannedMatchRejection::query()->count())->toBe(0);
});

it('tells the other components on the page when a suggestion is confirmed', function () {
    [$suggestion] = reviewWorld($this);

    reviewPage($this)->call('confirmPlannedPayment', $suggestion->id)->assertDispatched('occurrence-payment-changed');
});

it('locks the plan before the occurrence when unlinking', function () {
    [, $tx, $plan, $occurrence] = reviewWorld($this);
    $tx->update(['planned_transaction_id' => $plan->id]);
    app(App\Services\Recurring\OccurrencePaymentRecorder::class)->attach($this->user, $tx->id, $occurrence->id);
    $paymentId = PlannedPayment::query()->sole()->id;

    $locks = lockLog(fn () => app(App\Services\Recurring\PlannedPaymentReview::class)->unlink($this->user, $paymentId));
    $tables = array_column($locks, 'table');

    expect(array_search('planned_transactions', $tables, true))->toBeLessThan(array_search('planned_occurrences', $tables, true));
    expectTransactionsFirst($locks);
});
