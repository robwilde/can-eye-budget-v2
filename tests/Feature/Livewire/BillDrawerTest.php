<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\PlannedTransactionType;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Livewire\BillDrawer;
use App\Livewire\Bills;
use App\Livewire\TransactionModal;
use App\Models\Account;
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
    $this->travelTo(CarbonImmutable::parse('2026-06-15 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create(['name' => 'Everyday']);
    $this->user->update(['primary_account_id' => $this->account->id]);
});

function drawerPlan(object $test, string $name = 'Gym', int $cents = 10000, array $attributes = [], ?User $user = null, ?Account $account = null): PlannedTransaction
{
    return PlannedTransaction::factory()->for($user ?? $test->user)->for($account ?? $test->account)->create([
        'description' => $name, 'amount' => $cents, 'direction' => TransactionDirection::Debit, 'type' => PlannedTransactionType::Bill,
        'frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => '2026-01-10', 'until_date' => null, 'merchant_key' => null, ...$attributes,
    ]);
}

function drawerPaid(PlannedTransaction $plan, string $date, int $amount, ?Transaction $transaction = null): PlannedOccurrence
{
    $occurrence = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => $date, 'due_date' => $date, 'status' => OccurrenceStatus::Paid, 'closed_at' => now()]);
    PlannedPayment::factory()->create(['planned_occurrence_id' => $occurrence->id, 'transaction_id' => $transaction?->id, 'amount' => $amount, 'paid_on' => $date]);

    return $occurrence;
}

it('shows paid of total for an installment plan', function () {
    $plan = drawerPlan($this, 'Couch', 5000, ['type' => PlannedTransactionType::Installment, 'end_after_count' => 4, 'start_date' => '2026-05-01']);
    drawerPaid($plan, '2026-05-01', 5000);

    Livewire::actingAs($this->user)->test(BillDrawer::class)->dispatch('open-bill-drawer', planId: $plan->id)
        ->assertSee('1 paid of 4')->assertSee('2026-05-01, 2026-06-01, 2026-07-01, 2026-08-01');
});

it('summarises paid cycles in the bill drawer', function () {
    $plan = drawerPlan($this);
    drawerPaid($plan, '2026-03-10', 9000);
    drawerPaid($plan, '2026-04-10', 10000);
    drawerPaid($plan, '2026-05-10', 11000);

    Livewire::actingAs($this->user)->test(BillDrawer::class)->dispatch('open-bill-drawer', planId: $plan->id)
        ->assertSee('Average $100.00 · low $90.00 · high $110.00')->assertSee('2026-05-10 · $110.00');
});

it('opens the bill drawer from a direct link', function () {
    $plan = drawerPlan($this, 'Water');
    $this->actingAs($this->user)->get(route('bills', ['plan' => $plan->id]))->assertOk()->assertSee('data-testid="drawer-cadence"', false);

    Livewire::actingAs($this->user)->withQueryParams(['plan' => $plan->id])->test(Bills::class)->assertSeeHtml('data-testid="drawer-cadence"')->assertSee('Water');
});

it('does not open another users plan from a direct link', function () {
    $other = User::factory()->create();
    $theirs = drawerPlan($this, 'Secret plan', 12345, [], $other, Account::factory()->for($other)->create());

    Livewire::actingAs($this->user)->withQueryParams(['plan' => $theirs->id])->test(Bills::class)->assertDontSeeHtml('data-testid="drawer-cadence"')->assertDontSee('Secret plan')->assertDontSee('$123.45');

    Livewire::actingAs($this->user)->test(BillDrawer::class)->dispatch('open-bill-drawer', planId: $theirs->id)->assertSet('showDrawer', false)->assertSet('planId', null)->assertDontSee('Secret plan');
});

it('lists the plans a transaction paid', function () {
    $rent = drawerPlan($this, 'Rent');
    $power = drawerPlan($this, 'Power');
    $tx = Transaction::factory()->for($this->user)->for($this->account)->manual()->create(['amount' => -30000, 'direction' => TransactionDirection::Debit, 'post_date' => '2026-06-10', 'planned_transaction_id' => null]);
    $one = PlannedOccurrence::factory()->for($rent, 'plannedTransaction')->create(['original_date' => '2026-06-10', 'due_date' => '2026-06-10']);
    $two = PlannedOccurrence::factory()->for($power, 'plannedTransaction')->create(['original_date' => '2026-06-10', 'due_date' => '2026-06-10']);
    PlannedPayment::factory()->create(['planned_occurrence_id' => $one->id, 'transaction_id' => $tx->id, 'amount' => 20000, 'paid_on' => '2026-06-10']);
    PlannedPayment::factory()->create(['planned_occurrence_id' => $two->id, 'transaction_id' => $tx->id, 'amount' => 10000, 'paid_on' => '2026-06-10']);

    Livewire::actingAs($this->user)->test(TransactionModal::class)->dispatch('edit-transaction', id: $tx->id)
        ->assertSeeHtml('data-testid="transaction-paid-plans"')->assertSee('Rent')->assertSee('Power')->assertSeeHtml('bills?plan='.$rent->id);
});

it('lists no paid plans for a transaction that paid none', function () {
    $tx = Transaction::factory()->for($this->user)->for($this->account)->manual()->create(['post_date' => '2026-06-10']);

    Livewire::actingAs($this->user)->test(TransactionModal::class)->dispatch('edit-transaction', id: $tx->id)->assertDontSeeHtml('data-testid="transaction-paid-plans"');
});

it('never lists another users plan in a transaction detail', function () {
    $other = User::factory()->create();
    $theirs = drawerPlan($this, 'Their rent', 1000, [], $other, Account::factory()->for($other)->create());
    $tx = Transaction::factory()->for($this->user)->for($this->account)->manual()->create(['post_date' => '2026-06-10']);
    $occurrence = PlannedOccurrence::factory()->for($theirs, 'plannedTransaction')->create(['original_date' => '2026-06-10', 'due_date' => '2026-06-10']);
    PlannedPayment::factory()->create(['planned_occurrence_id' => $occurrence->id, 'transaction_id' => $tx->id, 'amount' => 500, 'paid_on' => '2026-06-10']);

    Livewire::actingAs($this->user)->test(TransactionModal::class)->dispatch('edit-transaction', id: $tx->id)->assertDontSee('Their rent');
});

it('shows next dates, the twelve month totals, the yearly totals and where it was last paid from', function () {
    $plan = drawerPlan($this, 'Insurance', 12000);
    $tx = Transaction::factory()->for($this->user)->for($this->account)->manual()->create(['post_date' => '2026-05-10', 'amount' => -12000]);
    drawerPaid($plan, '2026-05-10', 12000, $tx);
    drawerPaid($plan, '2025-05-10', 11000);
    drawerPaid($plan, '2023-05-10', 9000);

    Livewire::actingAs($this->user)->test(BillDrawer::class)->dispatch('open-bill-drawer', planId: $plan->id)
        ->assertSee('2026-07-10, 2026-08-10, 2026-09-10')->assertSee('2026: $120.00')->assertSee('2025: $110.00')->assertSee('2024: $0.00')->assertSee('2023: $90.00')
        ->assertSee('Last paid from Everyday')->assertSee('Annualised $1,440.00 · paid this year $120.00');
});

it('shows the pending payment match and the learned aliases', function () {
    $plan = drawerPlan($this, 'Power', 10000, ['learned_aliases' => ['descriptions' => ['agl energy'], 'merchant_keys' => []], 'amount_tolerance_bps' => 500]);
    $tx = Transaction::factory()->for($this->user)->for($this->account)->manual()->create(['description' => 'AGL PAYMENT', 'post_date' => '2026-06-12', 'amount' => -10400, 'planned_transaction_id' => null]);
    $occurrence = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-06-10', 'due_date' => '2026-06-10']);
    App\Models\AnalysisSuggestion::query()->create(['user_id' => $this->user->id, 'pipeline_run_id' => App\Models\PipelineRun::factory()->for($this->user)->create()->id, 'type' => App\Enums\SuggestionType::PlannedPaymentMatch, 'status' => App\Enums\SuggestionStatus::Pending, 'payload' => ['transaction_id' => $tx->id, 'planned_transaction_id' => $plan->id, 'planned_occurrence_id' => $occurrence->id, 'account_id' => $this->account->id, 'score' => 0.7]]);

    Livewire::actingAs($this->user)->test(BillDrawer::class)->dispatch('open-bill-drawer', planId: $plan->id)
        ->assertSeeHtml('data-testid="drawer-suggestion"')->assertSee('AGL PAYMENT')->assertSee('within 5% of the amount')->assertSee('agl energy');
});

it('opens the drawer from a row on the bills page', function () {
    $plan = drawerPlan($this, 'Gym');
    PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-06-20', 'due_date' => '2026-06-20']);

    Livewire::actingAs($this->user)->test(Bills::class)->assertSeeHtml('data-testid="bill-open"')->assertSeeHtml("open-bill-drawer', { planId: {$plan->id} }");

    Livewire::actingAs($this->user)->test(BillDrawer::class)->assertSet('showDrawer', false)->dispatch('open-bill-drawer', planId: $plan->id)->assertSet('showDrawer', true)->assertSet('planId', $plan->id)->call('close')->assertSet('showDrawer', false);
});

it('refreshes the drawer after a payment changes', function () {
    $plan = drawerPlan($this, 'Gym');
    $occurrence = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-06-20', 'due_date' => '2026-06-20']);

    $drawer = Livewire::actingAs($this->user)->test(BillDrawer::class)->dispatch('open-bill-drawer', planId: $plan->id)->assertSee('$0.00 / $100.00');

    PlannedPayment::factory()->create(['planned_occurrence_id' => $occurrence->id, 'transaction_id' => null, 'amount' => 4000, 'paid_on' => '2026-06-14']);

    $drawer->dispatch('occurrence-payment-changed')->assertSee('$40.00 / $100.00');
});

it('opens an ended bill and refuses income or non-bill transfers', function () {
    $ended = drawerPlan($this, 'Old gym', 5000, ['status' => App\Enums\PlannedTransactionStatus::Ended]);
    $income = drawerPlan($this, 'Salary', 200000, ['direction' => TransactionDirection::Credit, 'type' => PlannedTransactionType::Income]);
    $savings = Account::factory()->for($this->user)->create();
    $sweep = drawerPlan($this, 'Sweep', 1000, ['transfer_to_account_id' => $savings->id]);

    Livewire::actingAs($this->user)->test(BillDrawer::class)->dispatch('open-bill-drawer', planId: $ended->id)->assertSet('showDrawer', true)->assertSee('Old gym')
        ->dispatch('open-bill-drawer', planId: $income->id)->assertSet('showDrawer', false)
        ->dispatch('open-bill-drawer', planId: $sweep->id)->assertSet('showDrawer', false);
});

it('lists only bills, not income, in a transaction detail', function () {
    $salary = drawerPlan($this, 'Salary', 200000, ['direction' => TransactionDirection::Credit, 'type' => PlannedTransactionType::Income]);
    $tx = Transaction::factory()->for($this->user)->for($this->account)->manual()->create(['amount' => 200000, 'direction' => TransactionDirection::Credit, 'post_date' => '2026-06-10', 'planned_transaction_id' => null]);
    $occurrence = PlannedOccurrence::factory()->for($salary, 'plannedTransaction')->create(['original_date' => '2026-06-10', 'due_date' => '2026-06-10']);
    PlannedPayment::factory()->create(['planned_occurrence_id' => $occurrence->id, 'transaction_id' => $tx->id, 'amount' => 200000, 'paid_on' => '2026-06-10']);

    Livewire::actingAs($this->user)->test(TransactionModal::class)->dispatch('edit-transaction', id: $tx->id)->assertDontSeeHtml('data-testid="transaction-paid-plans"');
});
