<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PlannedTransactionStatus;
use App\Enums\PlanOrigin;
use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Enums\TransactionDirection;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Livewire\Bills;
use App\Livewire\PlanDeclarationForm;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineRun;
use App\Models\PlannedOccurrence;
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake([GeneratePlannedOccurrencesJob::class]);
    useAppTimezone('UTC');
    config(['budget.bills_page' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->user->update(['primary_account_id' => $this->account->id]);
});

function pageBill(object $test, string $name, int $cents, string $due = '2026-03-20', array $attributes = [], ?User $user = null, ?Account $account = null): PlannedTransaction
{
    $plan = PlannedTransaction::factory()->for($user ?? $test->user)->for($account ?? $test->account)->create([
        'description' => $name, 'amount' => $cents, 'direction' => TransactionDirection::Debit, 'frequency' => App\Enums\RecurrenceFrequency::EveryMonth,
        'start_date' => '2026-01-01', 'until_date' => null, 'merchant_key' => null, 'origin' => PlanOrigin::Declared, ...$attributes,
    ]);
    PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => $due, 'due_date' => $due]);

    return $plan;
}

function billsPage(object $test)
{
    return Livewire::actingAs($test->user)->test(Bills::class);
}

it('redirects the bills page home when recurring is off', function () {
    $this->actingAs($this->user)->get(route('bills'))->assertOk();

    $this->user->updatePreferences(['recurringEnabled' => false]);

    $this->actingAs($this->user->fresh())->get(route('bills'))->assertRedirect(route('dashboard'))->assertSessionHas('status');
});

it('hides the bills page and its link while the flag is off', function () {
    config(['budget.bills_page' => false]);

    $this->actingAs($this->user)->get(route('bills'))->assertNotFound();
    $this->actingAs($this->user)->get(route('dashboard'))->assertDontSee('data-testid="nav-bills"', false);

    config(['budget.bills_page' => true]);

    $this->actingAs($this->user)->get(route('dashboard'))->assertSee('data-testid="nav-bills"', false);

    $this->user->updatePreferences(['recurringEnabled' => false]);

    $this->actingAs($this->user->fresh())->get(route('dashboard'))->assertDontSee('data-testid="nav-bills"', false);
});

it('renders the overview sections and the KPIs', function () {
    pageBill($this, 'Rent', 100000, '2026-03-05');
    pageBill($this, 'Gym', 3000, '2026-03-20');
    pageBill($this, 'Insurance', 9000, '2026-05-01');
    pageBill($this, 'Old magazine', 1500, '2026-03-25', ['status' => PlannedTransactionStatus::Inactive]);

    billsPage($this)
        ->assertSeeHtmlInOrder(['data-testid="section-past-due"', 'Rent', 'data-testid="section-this-month"', 'Gym', 'data-testid="section-later"', 'Insurance', 'data-testid="section-dormant"', 'Old magazine'])
        ->assertSeeHtml('data-testid="kpi-past-due"')
        ->assertSee('$1,000.00');
});

it('searches all bills by name or merchant', function () {
    pageBill($this, 'Gym', 3000, '2026-03-20', ['merchant_key' => 'fitco']);
    pageBill($this, 'Landlord', 100000);

    billsPage($this)->call('showTab', 'all')->set('search', 'gym')->assertSeeHtml('data-testid="bill-'.PlannedTransaction::query()->where('description', 'Gym')->value('id').'"')->assertDontSee('Landlord')
        ->set('search', 'fit')->assertSee('Gym')->assertDontSee('Landlord');
});

it('shows inactive plans under the paused filter', function () {
    pageBill($this, 'Paused one', 1000, '2026-03-20', ['status' => PlannedTransactionStatus::Inactive]);
    pageBill($this, 'Ended one', 1000, '2026-03-21', ['status' => PlannedTransactionStatus::Ended]);
    pageBill($this, 'Live one', 1000, '2026-03-22');

    billsPage($this)->call('showTab', 'all')->set('lifecycle', 'paused')->assertSee('Paused one')->assertDontSee('Ended one')->assertDontSee('Live one')
        ->set('lifecycle', 'ended')->assertSee('Ended one')->assertDontSee('Paused one');
});

it('filters by type and sorts by name and amount', function () {
    pageBill($this, 'Alpha', 1000);
    pageBill($this, 'Zulu', 9000, '2026-03-21', ['type' => App\Enums\PlannedTransactionType::Subscription]);
    pageBill($this, 'Mid', 5000, '2026-03-22');

    billsPage($this)->call('showTab', 'all')->assertSeeInOrder(['Alpha', 'Mid', 'Zulu'])
        ->call('sortBy', 'amount')->assertSeeInOrder(['Alpha', 'Mid', 'Zulu'])->call('sortBy', 'amount')->assertSeeInOrder(['Zulu', 'Mid', 'Alpha'])
        ->set('type', 'subscription')->assertSee('Zulu')->assertDontSee('Alpha');
});

it('clears the first visit prompt once the user declares a bill', function () {
    foreach (range(1, 3) as $i) {
        AnalysisSuggestion::query()->create(['user_id' => $this->user->id, 'pipeline_run_id' => PipelineRun::factory()->for($this->user)->create()->id, 'type' => SuggestionType::RecurringTransaction, 'status' => SuggestionStatus::Pending, 'payload' => ['description' => "S{$i}", 'account_id' => $this->account->id]]);
    }

    billsPage($this)->assertSet('firstVisitCount', 3)->assertSeeHtml('data-testid="first-visit-prompt"');

    Livewire::actingAs($this->user)->test(PlanDeclarationForm::class)->call('open', 'bill')->set('description', 'Rent')->set('amount', '100')->set('startDate', '2026-04-01')->set('frequency', 'every-month')->call('save')->assertHasNoErrors();

    billsPage($this)->assertSet('firstVisitCount', 0)->assertDontSeeHtml('data-testid="first-visit-prompt"');
});

it('clears the first visit prompt after a dismissal or a recorded payment', function () {
    $suggest = fn () => AnalysisSuggestion::query()->create(['user_id' => $this->user->id, 'pipeline_run_id' => PipelineRun::factory()->for($this->user)->create()->id, 'type' => SuggestionType::RecurringTransaction, 'status' => SuggestionStatus::Pending, 'payload' => ['description' => 'X', 'account_id' => $this->account->id]]);
    $first = $suggest();
    $suggest();

    billsPage($this)->assertSet('firstVisitCount', 2);

    $first->update(['status' => SuggestionStatus::Rejected]);

    billsPage($this)->assertSet('firstVisitCount', 0);

    $first->update(['status' => SuggestionStatus::Pending]);
    $plan = pageBill($this, 'Detected one', 1000, '2026-03-20', ['origin' => PlanOrigin::Detected]);
    PlannedPayment::factory()->create(['planned_occurrence_id' => $plan->occurrences()->value('id'), 'transaction_id' => null, 'amount' => 500]);

    billsPage($this)->assertSet('firstVisitCount', 0);
});

it('offers review for a bill with a confirmable payment match and find for the rest', function () {
    $matched = pageBill($this, 'Matched', 1000, '2026-03-20');
    $plain = pageBill($this, 'Plain', 2000, '2026-03-21');
    $tx = Transaction::factory()->for($this->user)->for($this->account)->create(['planned_transaction_id' => null]);
    $occurrence = $matched->occurrences()->sole();
    AnalysisSuggestion::query()->create(['user_id' => $this->user->id, 'pipeline_run_id' => PipelineRun::factory()->for($this->user)->create()->id, 'type' => SuggestionType::PlannedPaymentMatch, 'status' => SuggestionStatus::Pending, 'payload' => ['transaction_id' => $tx->id, 'planned_transaction_id' => $matched->id, 'planned_occurrence_id' => $occurrence->id, 'account_id' => $this->account->id, 'score' => 0.7]]);

    $page = billsPage($this)->assertSeeHtmlInOrder(['data-testid="bill-'.$matched->id.'"', 'data-testid="bill-review"', 'data-testid="bill-'.$plain->id.'"', 'data-testid="bill-find"'])->assertSeeHtml('rules?account='.$this->account->id);

    expect(mb_substr_count($page->html(), 'data-testid="bill-review"'))->toBe(1);
});

it('offers find, not review, when the suggestion can no longer be confirmed', function () {
    $matched = pageBill($this, 'Matched', 1000, '2026-03-20');
    $tx = Transaction::factory()->for($this->user)->for($this->account)->create(['planned_transaction_id' => $matched->id]);
    AnalysisSuggestion::query()->create(['user_id' => $this->user->id, 'pipeline_run_id' => PipelineRun::factory()->for($this->user)->create()->id, 'type' => SuggestionType::PlannedPaymentMatch, 'status' => SuggestionStatus::Pending, 'payload' => ['transaction_id' => $tx->id, 'planned_transaction_id' => $matched->id, 'planned_occurrence_id' => $matched->occurrences()->sole()->id, 'account_id' => $this->account->id, 'score' => 0.7]]);

    billsPage($this)->assertDontSeeHtml('data-testid="bill-review"')->assertSeeHtml('data-testid="bill-find"');
});

it('links review to the account the match is on and the rules page opens that account', function () {
    $card = Account::factory()->for($this->user)->create();
    $matched = pageBill($this, 'Card bill', 1000, '2026-03-20', [], null, $card);
    $tx = Transaction::factory()->for($this->user)->for($card)->create(['planned_transaction_id' => null]);
    AnalysisSuggestion::query()->create(['user_id' => $this->user->id, 'pipeline_run_id' => PipelineRun::factory()->for($this->user)->create()->id, 'type' => SuggestionType::PlannedPaymentMatch, 'status' => SuggestionStatus::Pending, 'payload' => ['transaction_id' => $tx->id, 'planned_transaction_id' => $matched->id, 'planned_occurrence_id' => $matched->occurrences()->sole()->id, 'account_id' => $card->id, 'score' => 0.7]]);

    billsPage($this)->assertSeeHtml('rules?account='.$card->id);

    $this->actingAs($this->user);
    Livewire::withQueryParams(['account' => $card->id])->test(App\Livewire\RecurringTransactionReview::class)->assertSet('accountId', $card->id)->assertSeeHtml('payment-suggestion-');
});

it('does not let the rules page open another users account from the link', function () {
    $other = User::factory()->create();
    $theirs = Account::factory()->for($other)->create();

    Livewire::actingAs($this->user)->withQueryParams(['account' => $theirs->id])->test(App\Livewire\RecurringTransactionReview::class)->assertSet('accountId', $this->account->id);
});

it('refuses the scan from the page once recurring is switched off in another tab', function () {
    config(['budget.recurring_detection' => true]);
    $page = billsPage($this);
    $this->user->updatePreferences(['recurringEnabled' => false]);
    foreach (['2026-01-15', '2026-02-15', '2026-03-15'] as $date) {
        Transaction::factory()->fromRedbark()->for($this->user)->for($this->account)->create(['merchant_name' => 'Netflix', 'description' => 'NETFLIX', 'amount' => 1699, 'direction' => TransactionDirection::Debit, 'post_date' => $date, 'planned_transaction_id' => null]);
    }

    $page->call('findMyBills');

    expect(AnalysisSuggestion::query()->count())->toBe(0);
});

it('does not show the empty state to a user whose only bills fall due later', function () {
    pageBill($this, 'Annual insurance', 90000, '2027-01-20');

    billsPage($this)->assertDontSeeHtml('data-testid="bills-empty"');
});

it('runs the locked scan from the empty state', function () {
    config(['budget.recurring_detection' => true]);
    foreach (['2026-01-15', '2026-02-15', '2026-03-15'] as $date) {
        Transaction::factory()->fromRedbark()->for($this->user)->for($this->account)->create(['merchant_name' => 'Netflix', 'description' => 'NETFLIX', 'amount' => 1699, 'direction' => TransactionDirection::Debit, 'post_date' => $date, 'planned_transaction_id' => null]);
    }

    billsPage($this)->assertSeeHtml('data-testid="bills-empty"')->call('findMyBills')->assertSee('Found 1 new recurring suggestion');

    expect(AnalysisSuggestion::query()->ofType(SuggestionType::RecurringTransaction)->pending()->count())->toBe(1);
});

it('never shows another users bills', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->for($other)->create();
    pageBill($this, 'Theirs', 5000, '2026-03-20', [], $other, $otherAccount);
    pageBill($this, 'Mine', 1000, '2026-03-20', ['origin' => PlanOrigin::Detected]);
    AnalysisSuggestion::query()->create(['user_id' => $other->id, 'pipeline_run_id' => PipelineRun::factory()->for($other)->create()->id, 'type' => SuggestionType::RecurringTransaction, 'status' => SuggestionStatus::Pending, 'payload' => ['description' => 'X', 'account_id' => $otherAccount->id]]);

    billsPage($this)->assertSee('Mine')->assertDontSee('Theirs')->assertSet('firstVisitCount', 0)->call('showTab', 'all')->assertSee('Mine')->assertDontSee('Theirs');
});

it('adds no not audited exemptions for the bills page', function () {
    $methods = collect((new ReflectionClass(Bills::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === Bills::class)
        ->filter(fn (ReflectionMethod $method): bool => $method->getAttributes(App\Livewire\Attributes\NotAudited::class) !== []);

    expect($methods)->toHaveCount(0);

    $component = app('livewire')->new(Bills::class);

    foreach (['showTab', 'sortBy', 'findMyBills'] as $method) {
        expect(App\Livewire\Hooks\AuditComponentCalls::auditedAction($component, $method))->not->toBeNull();
    }
});

it('audits finding bills from the page', function () {
    billsPage($this)->call('findMyBills');

    expect(App\Models\AuditEvent::query()->where('action', 'livewire.bills.findMyBills')->sole()->user_id)->toBe($this->user->id);
});

it('refreshes when a payment changes or a plan is declared', function () {
    $plan = pageBill($this, 'Gym', 3000);
    $page = billsPage($this)->assertSee('$30.00');

    PlannedOccurrence::query()->where('planned_transaction_id', $plan->id)->update(['expected_amount' => 4500]);

    $page->dispatch('occurrence-payment-changed')->assertSee('$45.00');

    PlannedOccurrence::query()->where('planned_transaction_id', $plan->id)->update(['expected_amount' => 5500]);

    $page->dispatch('plan-declared', accountId: $this->account->id)->assertSee('$55.00');
});
