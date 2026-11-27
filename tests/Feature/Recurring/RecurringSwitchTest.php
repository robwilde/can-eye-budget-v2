<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\PipelineContext;
use App\Enums\PlannedTransactionStatus;
use App\Enums\PlanOrigin;
use App\Enums\RecurrenceFrequency;
use App\Enums\SuggestionType;
use App\Enums\TransactionDirection;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Jobs\RunTransactionAnalysisJob;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\AuditEvent;
use App\Models\PipelineRun;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PipelineStages\IdentifyRecurringTransactionsStage;
use App\Services\Recurring\PlannedOccurrenceGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    useAppTimezone('UTC');
    config(['budget.recurring_detection' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-03-20 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->user->update(['primary_account_id' => $this->account->id]);
});

function switchPatterns(object $test, string $name, int $amount, ?Account $account = null, ?User $user = null): void
{
    foreach (['2026-01-15', '2026-02-15', '2026-03-15'] as $date) {
        Transaction::factory()->fromRedbark()->for($user ?? $test->user)->for($account ?? $test->account)->create([
            'merchant_name' => $name, 'description' => mb_strtoupper($name), 'amount' => $amount, 'direction' => TransactionDirection::Debit, 'post_date' => $date, 'planned_transaction_id' => null,
        ]);
    }
}

function switchOff(User $user): void
{
    $user->updatePreferences(['recurringEnabled' => false]);
}

function switchStage(User $user): int
{
    $run = PipelineRun::factory()->for($user)->create();
    $context = new PipelineContext(user: $user->fresh(), pipelineRun: $run, isFirstSync: false);
    $stage = app(IdentifyRecurringTransactionsStage::class);

    return $stage->shouldRun($context) ? count($stage->execute($context)->suggestionIds) : -1;
}

it('skips recurring detection when recurring is off', function () {
    switchPatterns($this, 'Netflix', 1699);

    expect(switchStage($this->user))->toBe(1);

    AnalysisSuggestion::query()->delete();
    switchOff($this->user);

    expect(switchStage($this->user))->toBe(-1)->and(AnalysisSuggestion::query()->ofType(SuggestionType::RecurringTransaction)->count())->toBe(0);
});

it('keeps plans but stops generation when recurring is off', function () {
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create(['frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2026-01-10', 'until_date' => null]);
    PlannedOccurrence::query()->delete();
    switchOff($this->user);

    new GeneratePlannedOccurrencesJob($this->user->fresh())->handle(app(PlannedOccurrenceGenerator::class));

    expect(PlannedOccurrence::query()->count())->toBe(0)->and($plan->fresh()->status)->toBe(PlannedTransactionStatus::Active);

    $this->user->updatePreferences(['recurringEnabled' => true]);
    new GeneratePlannedOccurrencesJob($this->user->fresh())->handle(app(PlannedOccurrenceGenerator::class));

    expect(PlannedOccurrence::query()->count())->toBeGreaterThan(0);
});

it('refuses a recurring transfer while recurring is off', function () {
    $savings = Account::factory()->for($this->user)->create();
    $existing = PlannedTransaction::factory()->for($this->user)->for($this->account)->create(['transfer_to_account_id' => $savings->id]);
    switchOff($this->user);

    expect(fn () => PlannedTransaction::factory()->for($this->user)->for($this->account)->create(['transfer_to_account_id' => $savings->id]))->toThrow(ValidationException::class)
        ->and(fn () => PlannedTransaction::factory()->for($this->user)->for($this->account)->create(['description' => 'Bill'])->update(['transfer_to_account_id' => $savings->id]))->toThrow(ValidationException::class);

    $existing->refresh()->update(['description' => 'Still editable']);

    expect($existing->fresh()->description)->toBe('Still editable')->and(PlannedTransaction::query()->whereNotNull('transfer_to_account_id')->count())->toBe(1);
});

it('refuses a recurring transfer from the plan editors while recurring is off', function () {
    $savings = Account::factory()->for($this->user)->create();
    switchOff($this->user);

    Livewire::actingAs($this->user)->test(App\Livewire\TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-04-01')
        ->set('mode', 'plan')->set('transactionType', 'transfer')->set('descriptionInput', '50 Sweep')->set('accountId', $this->account->id)->set('transferToAccountId', $savings->id)
        ->call('save')->assertHasErrors('mode')->assertSee('Recurring is switched off');

    expect(PlannedTransaction::query()->count())->toBe(0);
});

it('reports how many new suggestions a settings scan created', function () {
    switchPatterns($this, 'Netflix', 1699);
    switchPatterns($this, 'Spotify', 1299);

    Livewire::actingAs($this->user)->test('pages::settings.recurring')->call('identifyPatterns')->assertSee('Found 2 new recurring suggestions');

    expect(AnalysisSuggestion::query()->ofType(SuggestionType::RecurringTransaction)->pending()->count())->toBe(2);
});

it('scans across every account from the settings page', function () {
    $second = Account::factory()->for($this->user)->create();
    switchPatterns($this, 'Netflix', 1699);
    switchPatterns($this, 'Spotify', 1299, $second);

    Livewire::actingAs($this->user)->test('pages::settings.recurring')->call('identifyPatterns')->assertSee('Found 2 new recurring suggestions');
});

it('says a scan is already running when analysis holds the lock', function () {
    switchPatterns($this, 'Netflix', 1699);
    $lock = Cache::lock(RunTransactionAnalysisJob::lockKey($this->user->id), 60);
    $lock->get();

    Livewire::actingAs($this->user)->test('pages::settings.recurring')->call('identifyPatterns')->assertSee('A scan is already running');

    $lock->release();

    expect(AnalysisSuggestion::query()->count())->toBe(0);
});

it('switching recurring off leaves other users untouched', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->for($other)->create();
    switchPatterns($this, 'Netflix', 1699);
    switchPatterns($this, 'Netflix', 1699, $otherAccount, $other);

    Livewire::actingAs($this->user)->test('pages::settings.recurring')->call('toggleRecurring');

    expect($this->user->fresh()->preferences->recurringEnabled)->toBeFalse()->and($other->fresh()->preferences->recurringEnabled)->toBeTrue()
        ->and(switchStage($this->user))->toBe(-1)->and(switchStage($other))->toBe(1);
});

it('audits switching recurring off', function () {
    Livewire::actingAs($this->user)->test('pages::settings.recurring')->call('toggleRecurring');

    expect(AuditEvent::query()->where('action', 'livewire.pages::settings.recurring.toggleRecurring')->sole()->user_id)->toBe($this->user->id);
});

it('pauses and deletes plans from the settings page', function () {
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create(['origin' => PlanOrigin::Declared]);
    $detected = PlannedTransaction::factory()->for($this->user)->for($this->account)->create(['origin' => PlanOrigin::Detected]);

    Livewire::actingAs($this->user)->test('pages::settings.recurring')->call('togglePlan', $plan->id)->call('deletePlan', $detected->id);

    expect($plan->fresh()->status)->toBe(PlannedTransactionStatus::Inactive)->and($detected->fresh()->status)->toBe(PlannedTransactionStatus::Ended);

    Livewire::actingAs($this->user)->test('pages::settings.recurring')->call('deletePlan', $plan->id);

    expect(PlannedTransaction::query()->find($plan->id))->toBeNull();
});

it('refuses to pause or delete another users plan from the settings page', function (string $method) {
    $other = User::factory()->create();
    $theirs = PlannedTransaction::factory()->for($other)->for(Account::factory()->for($other))->create();

    Livewire::actingAs($this->user)->test('pages::settings.recurring')->call($method, $theirs->id)->assertNotFound();

    expect($theirs->fresh()->status)->toBe(PlannedTransactionStatus::Active)->and(PlannedTransaction::query()->find($theirs->id))->not->toBeNull();
})->with(['togglePlan', 'deletePlan']);

it('leaves pay cycle income to its own page', function () {
    $income = PlannedTransaction::factory()->for($this->user)->for($this->account)->create(['is_pay_cycle_income' => true]);

    Livewire::actingAs($this->user)->test('pages::settings.recurring')->call('togglePlan', $income->id)->assertNotFound();

    expect($income->fresh()->status)->toBe(PlannedTransactionStatus::Active);
});

it('cleans up stale plans from the settings page and reports it', function () {
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create(['origin' => PlanOrigin::Detected, 'frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2024-01-10']);
    PlannedTransaction::query()->whereKey($plan->id)->update(['updated_at' => now()->subYear()]);

    Livewire::actingAs($this->user)->test('pages::settings.recurring')->call('cleanUpStale')->assertSee('1 plan(s) paused');

    expect($plan->fresh()->status)->toBe(PlannedTransactionStatus::Inactive);
});

it('refuses settings scans while recurring is off', function () {
    switchPatterns($this, 'Netflix', 1699);
    switchOff($this->user);

    Livewire::actingAs($this->user)->test('pages::settings.recurring')->call('identifyPatterns')->assertSee('switched off');

    expect(AnalysisSuggestion::query()->count())->toBe(0);
});

it('refuses the rules page scan while recurring is off', function () {
    switchPatterns($this, 'Netflix', 1699);
    switchOff($this->user);

    Livewire::actingAs($this->user)->test(App\Livewire\RecurringTransactionReview::class)->call('findRecurring');

    expect(AnalysisSuggestion::query()->count())->toBe(0);
});

it('redirects home with a notice through the middleware when recurring is off', function () {
    Illuminate\Support\Facades\Route::middleware(['web', 'auth', App\Http\Middleware\EnsureRecurringEnabled::class])->get('/_recurring-probe', fn () => 'ok')->name('recurring.probe');

    $this->actingAs($this->user)->get('/_recurring-probe')->assertOk();

    switchOff($this->user);

    $this->actingAs($this->user->fresh())->get('/_recurring-probe')->assertRedirect(route('dashboard'))->assertSessionHas('status');
});

it('shows the recurring settings page and the switch state', function () {
    $this->actingAs($this->user)->get(route('recurring.edit'))->assertOk()->assertSee('Recurring bills and income');
});

it('queues a history rebuild when recurring is switched back on', function () {
    switchOff($this->user);
    Illuminate\Support\Facades\Bus::fake([GeneratePlannedOccurrencesJob::class]);

    Livewire::actingAs($this->user->fresh())->test('pages::settings.recurring')->call('toggleRecurring');

    Illuminate\Support\Facades\Bus::assertDispatched(GeneratePlannedOccurrencesJob::class, fn ($job): bool => $job->rebuildHistory === true);

    Livewire::actingAs($this->user->fresh())->test('pages::settings.recurring')->call('toggleRecurring');

    Illuminate\Support\Facades\Bus::assertDispatchedTimes(GeneratePlannedOccurrencesJob::class, 1);
});

it('runs no nightly maintenance for a user who switched recurring off', function () {
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create(['origin' => PlanOrigin::Detected, 'frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2024-01-10']);
    PlannedTransaction::query()->whereKey($plan->id)->update(['updated_at' => now()->subYear()]);
    switchOff($this->user);

    new App\Jobs\RecurringMaintenanceJob($this->user->fresh())->handle(app(App\Services\Recurring\PriceChangeDetector::class), app(App\Services\Recurring\StalePlanSweeper::class));

    expect($plan->fresh()->status)->toBe(PlannedTransactionStatus::Active);
});
