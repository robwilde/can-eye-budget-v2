<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\RecurringCandidate;
use App\Enums\PlannedTransactionStatus;
use App\Enums\PlannedTransactionType;
use App\Enums\PlanOrigin;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Livewire\PlannedTransactionManager;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\PipelineRun;
use App\Models\PlannedOccurrence;
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PlannedTransactionMatcher;
use App\Services\Recurring\PlanDuplicateGuard;
use App\Services\Recurring\RecurringSuggestionWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->user->update(['primary_account_id' => $this->account->id]);
});

function statusPlan(object $test, array $attributes = []): PlannedTransaction
{
    return PlannedTransaction::factory()->for($test->user)->for($test->account)->create([
        'description' => 'Netflix', 'amount' => 1699, 'direction' => TransactionDirection::Debit, 'frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2026-01-05', 'until_date' => null, ...$attributes,
    ]);
}

function statusManager(object $test, ?User $user = null)
{
    return Livewire::actingAs($user ?? $test->user)->test(PlannedTransactionManager::class);
}

it('generates no occurrences for a paused plan', function () {
    $plan = statusPlan($this);
    (new GeneratePlannedOccurrencesJob($this->user))->handle(app(App\Services\Recurring\PlannedOccurrenceGenerator::class));
    $before = PlannedOccurrence::query()->where('planned_transaction_id', $plan->id)->count();

    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    statusManager($this)->call('toggleActive', $plan->id);
    $this->travelTo(CarbonImmutable::parse('2026-07-10 12:00:00', 'UTC'));
    (new GeneratePlannedOccurrencesJob($this->user))->handle(app(App\Services\Recurring\PlannedOccurrenceGenerator::class));

    expect($plan->fresh()->status)->toBe(PlannedTransactionStatus::Inactive)
        ->and($before)->toBeGreaterThan(0)
        ->and(PlannedOccurrence::query()->where('planned_transaction_id', $plan->id)->count())->toBe($before);
});

it('tombstones a deleted detected plan', function () {
    $plan = statusPlan($this, ['origin' => PlanOrigin::Detected, 'description' => 'NETFLIX COM']);
    $candidate = new RecurringCandidate('NETFLIX COM', 'Netflix', 1699, TransactionDirection::Debit, RecurrenceFrequency::EveryMonth, $this->account->id, null, [], CarbonImmutable::parse('2026-01-05'), 0.9);
    $writer = app(RecurringSuggestionWriter::class);

    expect($writer->skipReason($this->user, $candidate))->toBe('existing_planned_transaction');

    statusManager($this)->call('confirmDelete', $plan->id)->call('delete')->assertHasNoErrors();

    expect($plan->fresh()->status)->toBe(PlannedTransactionStatus::Ended)
        ->and($writer->skipReason($this->user, $candidate))->toBe('existing_planned_transaction')
        ->and($writer->writeForRun($this->user, PipelineRun::factory()->for($this->user)->create(), collect([$candidate])))->toBe([]);
});

it('hard deletes a declared plan with its occurrences and payments', function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    $plan = statusPlan($this);
    $occurrences = collect(['2026-01-05', '2026-02-05', '2026-03-05'])->map(fn (string $date): PlannedOccurrence => PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => $date, 'due_date' => $date]));
    PlannedPayment::factory()->create(['planned_occurrence_id' => $occurrences[0]->id, 'transaction_id' => null, 'amount' => 1699]);
    PlannedPayment::factory()->create(['planned_occurrence_id' => $occurrences[1]->id, 'transaction_id' => null, 'amount' => 800]);
    $linked = Transaction::factory()->for($this->user)->for($this->account)->create(['planned_transaction_id' => $plan->id]);

    statusManager($this)->call('confirmDelete', $plan->id)->call('delete')->assertHasNoErrors();

    expect(PlannedTransaction::query()->find($plan->id))->toBeNull()
        ->and(PlannedOccurrence::query()->where('planned_transaction_id', $plan->id)->count())->toBe(0)
        ->and(PlannedPayment::query()->count())->toBe(0)
        ->and($linked->fresh()->planned_transaction_id)->toBeNull();
});

it('resumes any non-active plan to active', function (PlannedTransactionStatus $status) {
    $plan = statusPlan($this, ['status' => $status]);

    statusManager($this)->call('toggleActive', $plan->id);

    expect($plan->fresh()->status)->toBe(PlannedTransactionStatus::Active);
})->with([PlannedTransactionStatus::Inactive, PlannedTransactionStatus::Ended]);

it('pauses an active plan', function () {
    $plan = statusPlan($this);

    statusManager($this)->call('toggleActive', $plan->id);

    expect($plan->fresh()->status)->toBe(PlannedTransactionStatus::Inactive);
});

it('refuses to pause or delete another users plan', function () {
    $other = User::factory()->create();
    $theirs = PlannedTransaction::factory()->for($other)->for(Account::factory()->for($other))->create(['origin' => PlanOrigin::Detected]);

    statusManager($this)->call('toggleActive', $theirs->id)->call('confirmDelete', $theirs->id)->call('delete');

    expect($theirs->fresh()->status)->toBe(PlannedTransactionStatus::Active)->and(PlannedTransaction::query()->find($theirs->id))->not->toBeNull();

    statusManager($this)->set('deletingId', $theirs->id)->call('delete');

    expect($theirs->fresh()->status)->toBe(PlannedTransactionStatus::Active);
});

it('audits deleting a plan', function () {
    $plan = statusPlan($this);

    statusManager($this)->call('confirmDelete', $plan->id)->call('delete');

    expect(AuditEvent::query()->where('action', 'livewire.planned-transaction-manager.delete')->sole()->user_id)->toBe($this->user->id);
});

it('keeps a plan that reaches its end date active', function () {
    $plan = statusPlan($this, ['until_date' => '2026-02-01']);

    (new GeneratePlannedOccurrencesJob($this->user))->handle(app(App\Services\Recurring\PlannedOccurrenceGenerator::class));

    expect($plan->fresh()->status)->toBe(PlannedTransactionStatus::Active);
});

it('hides an ended plan from the manager and keeps paused ones listed', function () {
    $ended = statusPlan($this, ['description' => 'Tombstone', 'status' => PlannedTransactionStatus::Ended]);
    $paused = statusPlan($this, ['description' => 'Paused one', 'status' => PlannedTransactionStatus::Inactive]);

    statusManager($this)->assertSee('Paused one')->assertDontSee('Tombstone');
});

it('lets an ended plan neither generate, match nor block a new declaration', function () {
    $ended = statusPlan($this, ['status' => PlannedTransactionStatus::Ended]);
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    Transaction::factory()->for($this->user)->for($this->account)->create(['amount' => -1699, 'direction' => TransactionDirection::Debit, 'post_date' => '2026-03-05', 'planned_transaction_id' => null]);

    (new GeneratePlannedOccurrencesJob($this->user))->handle(app(App\Services\Recurring\PlannedOccurrenceGenerator::class));

    expect(PlannedOccurrence::query()->where('planned_transaction_id', $ended->id)->count())->toBe(0)
        ->and(app(PlannedTransactionMatcher::class)->matchForUser($this->user))->toBe(0)
        ->and(app(PlanDuplicateGuard::class)->exists($this->user, $this->account->id, 1699, TransactionDirection::Debit, null, 'Netflix'))->toBeFalse();
});

it('retypes a transfer plan whose destination account is deleted', function () {
    $savings = Account::factory()->for($this->user)->create();
    $plan = statusPlan($this, ['transfer_to_account_id' => $savings->id, 'description' => 'Top up']);
    $credit = statusPlan($this, ['transfer_to_account_id' => $savings->id, 'direction' => TransactionDirection::Credit, 'description' => 'Sweep']);

    expect($plan->fresh()->type)->toBe(PlannedTransactionType::Transfer);

    $savings->delete();

    expect($plan->fresh()->transfer_to_account_id)->toBeNull()
        ->and($plan->fresh()->type)->toBe(PlannedTransactionType::Bill)
        ->and($credit->fresh()->type)->toBe(PlannedTransactionType::Income);
});

it('tombstones a detected plan deleted from the calendar modal and removes a declared one', function () {
    $detected = statusPlan($this, ['origin' => PlanOrigin::Detected, 'description' => 'Detected one']);
    $declared = statusPlan($this, ['description' => 'Declared one']);

    Livewire::actingAs($this->user)->test(App\Livewire\TransactionModal::class)->set('editingPlannedTransactionId', $detected->id)->call('deletePlannedTransaction');
    Livewire::actingAs($this->user)->test(App\Livewire\TransactionModal::class)->set('editingPlannedTransactionId', $declared->id)->call('deletePlannedTransaction');

    expect($detected->fresh()->status)->toBe(PlannedTransactionStatus::Ended)->and($declared->fresh())->toBeNull();
});

it('supersedes the pending payment suggestions of a detected plan when it is deleted', function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    $plan = statusPlan($this, ['origin' => PlanOrigin::Detected]);
    $occurrence = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-03-05', 'due_date' => '2026-03-05']);
    $tx = Transaction::factory()->for($this->user)->for($this->account)->create(['planned_transaction_id' => null]);
    $suggestion = App\Models\AnalysisSuggestion::query()->create(['user_id' => $this->user->id, 'pipeline_run_id' => PipelineRun::factory()->for($this->user)->create()->id, 'type' => App\Enums\SuggestionType::PlannedPaymentMatch, 'status' => App\Enums\SuggestionStatus::Pending, 'payload' => ['transaction_id' => $tx->id, 'planned_transaction_id' => $plan->id, 'planned_occurrence_id' => $occurrence->id, 'account_id' => $this->account->id, 'score' => 0.7]]);

    statusManager($this)->call('confirmDelete', $plan->id)->call('delete');

    expect($suggestion->fresh()->status)->toBe(App\Enums\SuggestionStatus::Superseded);

    Livewire::actingAs($this->user)->test(App\Livewire\RecurringTransactionReview::class)->assertDontSeeHtml('data-testid="payment-suggestion-'.$suggestion->id.'"');
});

it('does not link transactions to an ended plan from a rule', function () {
    $ended = statusPlan($this, ['status' => PlannedTransactionStatus::Ended]);
    $live = statusPlan($this, ['description' => 'Live']);
    $lookups = new App\Services\RuleActionLookups;

    expect($lookups->planIsOwnedBy($ended->id, $this->user->id))->toBeFalse()->and($lookups->planIsOwnedBy($live->id, $this->user->id))->toBeTrue();
});

it('does not bring a deleted plan back when its BNPL order is rejected', function () {
    $plan = statusPlan($this, ['status' => PlannedTransactionStatus::Ended, 'origin' => PlanOrigin::Detected]);
    $order = App\Models\BnplOrder::factory()->for($this->user)->create(['account_id' => $this->account->id, 'planned_transaction_id' => $plan->id, 'status' => App\Enums\BnplOrderStatus::PendingReview]);

    Livewire::actingAs($this->user)->test(App\Livewire\BnplOrderReview::class)->call('reject', $order->id);

    expect($plan->fresh()->status)->toBe(PlannedTransactionStatus::Ended);
});

it('does not offer the upcoming plans widget for tombstones alone', function () {
    statusPlan($this, ['status' => PlannedTransactionStatus::Ended]);

    expect(App\Enums\DashboardWidget::UpcomingPlans->hasData($this->user))->toBeFalse();

    statusPlan($this, ['description' => 'Live']);

    expect(App\Enums\DashboardWidget::UpcomingPlans->hasData($this->user))->toBeTrue();
});

it('gives a new plan the active status without the factory', function () {
    expect(new PlannedTransaction()->status)->toBe(PlannedTransactionStatus::Active);
});
