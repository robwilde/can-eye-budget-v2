<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\PlannedTransactionType;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Merchant;
use App\Models\MerchantAlias;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function modalPlan(object $test, array $attributes = []): PlannedTransaction
{
    return PlannedTransaction::factory()->for($test->user)->for($test->account)->create([
        'start_date' => '2026-04-01',
        'amount' => 5000,
        'description' => 'Gym',
        ...$attributes,
    ]);
}

function modalEditing(object $test, PlannedTransaction $plan): Testable
{
    return Livewire::actingAs($test->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $plan->id);
}

function modalNewPlan(object $test, string $type = 'expense', string $input = '50 Gym'): Testable
{
    return Livewire::actingAs($test->user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('mode', 'plan')
        ->set('transactionType', $type)
        ->set('descriptionInput', $input)
        ->set('accountId', $test->account->id);
}

it('adds https to a payment link without a scheme', function () {
    $plan = modalPlan($this);

    modalEditing($this, $plan)
        ->set('paymentUrl', 'example.com/pay')
        ->call('save')
        ->assertHasNoErrors();

    expect($plan->fresh()->payment_url)->toBe('https://example.com/pay');
});

it('rejects a payment link that is not http or https', function () {
    $plan = modalPlan($this, ['payment_url' => 'https://old.example/pay']);

    modalEditing($this, $plan)
        ->set('paymentUrl', 'javascript:alert(1)')
        ->call('save')
        ->assertHasErrors(['paymentUrl']);

    expect($plan->fresh()->payment_url)->toBe('https://old.example/pay');
});

it('copies a payment link only to the users own matching plans', function () {
    $merchant = Merchant::factory()->for($this->user)->create(['name' => 'Netflix']);
    $otherUser = User::factory()->create();

    $plan = modalPlan($this, ['description' => 'Netflix', 'merchant_key' => $merchant->merchant_key]);
    $sameMerchant = modalPlan($this, ['description' => 'Netflix 4K', 'merchant_key' => $merchant->merchant_key]);
    $sameName = modalPlan($this, ['description' => 'Netflix']);
    $unrelated = modalPlan($this, ['description' => 'Gym']);
    $theirs = PlannedTransaction::factory()->for($otherUser)->for(Account::factory()->for($otherUser))->create([
        'description' => 'Netflix',
        'merchant_key' => $merchant->merchant_key,
    ]);

    modalEditing($this, $plan)
        ->assertSet('merchantId', $merchant->id)
        ->set('paymentUrl', 'netflix.example/pay')
        ->set('copyPaymentUrl', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($sameMerchant->fresh()->payment_url)->toBe('https://netflix.example/pay')
        ->and($sameName->fresh()->payment_url)->toBe('https://netflix.example/pay')
        ->and($unrelated->fresh()->payment_url)->toBeNull()
        ->and($theirs->fresh()->payment_url)->toBeNull();
});

it('copies a payment link from a newly planned bill', function () {
    $existing = modalPlan($this, ['description' => 'Gym']);

    modalNewPlan($this, input: '60 Gym')
        ->set('paymentUrl', 'gym.example/pay')
        ->set('copyPaymentUrl', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($existing->fresh()->payment_url)->toBe('https://gym.example/pay');
});

it('forces end after count when an installment gets a payment count', function () {
    $plan = modalPlan($this, [
        'type' => PlannedTransactionType::Installment,
        'until_date' => '2027-06-30',
    ]);

    modalEditing($this, $plan)
        ->assertSet('untilType', 'until-date')
        ->set('endAfterCount', '4')
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $plan->fresh();

    expect($fresh->end_after_count)->toBe(4)
        ->and($fresh->until_date)->toBeNull();
});

it('ends a plan after a payment count chosen with the end toggle', function () {
    modalNewPlan($this)
        ->set('untilType', 'after-count')
        ->set('endAfterCount', '12')
        ->call('save')
        ->assertHasNoErrors();

    $plan = PlannedTransaction::query()->where('user_id', $this->user->id)->sole();

    expect($plan->end_after_count)->toBe(12)
        ->and($plan->until_date)->toBeNull();
});

it('requires a payment count when ending after a count', function () {
    modalNewPlan($this)
        ->set('untilType', 'after-count')
        ->call('save')
        ->assertHasErrors(['endAfterCount']);

    expect(PlannedTransaction::query()->count())->toBe(0);
});

it('refuses an end count above 600', function () {
    modalNewPlan($this)
        ->set('untilType', 'after-count')
        ->set('endAfterCount', '601')
        ->call('save')
        ->assertHasErrors(['endAfterCount']);

    expect(PlannedTransaction::query()->count())->toBe(0);
});

it('reopens a plan that ends after a count on that end', function () {
    $plan = modalPlan($this, ['end_after_count' => 7]);

    modalEditing($this, $plan)->assertSet('untilType', 'after-count')->assertSet('endAfterCount', '7');
});

it('refuses a plan amount with more than two decimal places', function () {
    $plan = modalPlan($this);

    modalEditing($this, $plan)
        ->set('descriptionInput', '10.123 Gym')
        ->call('save')
        ->assertHasErrors(['descriptionInput']);

    expect($plan->fresh()->amount)->toBe(5000);

    modalNewPlan($this, 'expense', '10.123 Gym')->call('save')->assertHasErrors(['descriptionInput']);

    expect(PlannedTransaction::query()->count())->toBe(1);
});

it('still rounds an entered transaction amount with more than two decimal places', function () {
    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-01')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '10.123 Coffee')
        ->set('accountId', $this->account->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::query()->where('user_id', $this->user->id)->sole()->amount)->toBe(1012);
});

it('audits saving a plan with the new series fields', function () {
    $plan = modalPlan($this);

    modalEditing($this, $plan)
        ->set('planType', 'subscription')
        ->set('paymentUrl', 'example.com/pay')
        ->set('autopay', true)
        ->call('save')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->where('action', 'livewire.transaction-modal.save')->sole();

    expect($event->user_id)->toBe($this->user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success)
        ->and($plan->fresh()->autopay)->toBeTrue();
});

it('types a new income plan as income and a new expense plan as a bill', function () {
    modalNewPlan($this, 'income', '2500 Rent received')->call('save')->assertHasNoErrors();
    modalNewPlan($this, 'expense', '50 Gym')->call('save')->assertHasNoErrors();

    $types = PlannedTransaction::query()->where('user_id', $this->user->id)->pluck('type', 'description');

    expect($types['Rent received'])->toBe(PlannedTransactionType::Income)
        ->and($types['Gym'])->toBe(PlannedTransactionType::Bill);
});

it('saves the chosen type for a new plan', function () {
    modalNewPlan($this)->set('planType', 'subscription')->set('autopay', true)->call('save')->assertHasNoErrors();

    $plan = PlannedTransaction::query()->where('user_id', $this->user->id)->sole();

    expect($plan->type)->toBe(PlannedTransactionType::Subscription)
        ->and($plan->autopay)->toBeTrue();
});

it('falls back to the direction default when the chosen type does not suit it', function () {
    modalNewPlan($this, 'income', '2500 Rent')->set('planType', 'subscription')->call('save')->assertHasNoErrors();

    expect(PlannedTransaction::query()->sole()->type)->toBe(PlannedTransactionType::Income);
});

it('retypes a transfer plan switched to an expense or income', function (string $type, TransactionDirection $direction, PlannedTransactionType $expected) {
    $plan = modalPlan($this, ['transfer_to_account_id' => Account::factory()->for($this->user)->create()->id]);

    modalEditing($this, $plan)
        ->assertSet('planType', 'transfer')
        ->set('transactionType', $type)
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $plan->fresh();

    expect($fresh->transfer_to_account_id)->toBeNull()
        ->and($fresh->direction)->toBe($direction)
        ->and($fresh->type)->toBe($expected);
})->with([
    'expense' => ['expense', TransactionDirection::Debit, PlannedTransactionType::Bill],
    'income' => ['income', TransactionDirection::Credit, PlannedTransactionType::Income],
]);

it('keeps a plan with a destination typed as a transfer and locks the select', function () {
    $plan = modalPlan($this, ['transfer_to_account_id' => Account::factory()->for($this->user)->create()->id]);

    modalEditing($this, $plan)
        ->assertSee(__('A plan with a destination account is always a transfer.'))
        ->set('planType', 'bill')
        ->call('save')
        ->assertHasNoErrors();

    expect($plan->fresh()->type)->toBe(PlannedTransactionType::Transfer);
});

it('shows the series fields and merchant picker in plan mode', function () {
    modalNewPlan($this)
        ->assertSeeHtml('data-testid="plan-series-fields"')
        ->assertSeeHtml('data-testid="merchant-picker"')
        ->assertSeeHtml('data-testid="plan-payment-url"');
});

it('hides the series fields in enter mode', function () {
    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-01')
        ->assertDontSeeHtml('data-testid="plan-series-fields"');
});

it('stores the merchant and notes on the plan', function () {
    $merchant = Merchant::factory()->for($this->user)->create(['name' => 'Spotify']);

    modalNewPlan($this)
        ->set('merchantId', $merchant->id)
        ->set('notes', 'Family plan')
        ->call('save')
        ->assertHasNoErrors();

    $plan = PlannedTransaction::query()->sole();

    expect($plan->merchant_key)->toBe($merchant->merchant_key)
        ->and($plan->notes)->toBe('Family plan');
});

it('carries the merchant and notes of an entered transaction into the plan it becomes', function () {
    $merchant = Merchant::factory()->for($this->user)->create(['name' => 'Spotify']);
    $transaction = Transaction::factory()->for($this->user)->for($this->account)->manual()->create([
        'amount' => 1200,
        'direction' => TransactionDirection::Debit,
        'description' => 'Spotify',
        'post_date' => '2026-03-01',
        'merchant_id' => $merchant->id,
        'notes' => 'Duo',
    ]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('mode', 'plan')
        ->set('date', '2026-04-01')
        ->call('save')
        ->assertHasNoErrors();

    $plan = PlannedTransaction::query()->where('user_id', $this->user->id)->sole();

    expect($plan->merchant_key)->toBe($merchant->merchant_key)
        ->and($plan->notes)->toBe('Duo')
        ->and($plan->type)->toBe(PlannedTransactionType::Bill);
});

it('refuses a merchant that belongs to another user', function () {
    $foreign = Merchant::factory()->for(User::factory()->create())->create();

    modalNewPlan($this)->set('merchantId', $foreign->id)->call('save')->assertHasErrors(['merchantId']);

    expect(PlannedTransaction::query()->count())->toBe(0);
});

it('does not open another users plan for editing', function () {
    $theirs = PlannedTransaction::factory()->for(User::factory()->create())->create();

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $theirs->id)
        ->assertSet('editingPlannedTransactionId', null);
});

it('does not carry series fields from one plan into the next form', function () {
    $plan = modalPlan($this, ['payment_url' => 'https://old.example/pay', 'autopay' => true, 'end_after_count' => 5]);

    modalEditing($this, $plan)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->assertSet('paymentUrl', '')
        ->assertSet('autopay', false)
        ->assertSet('endAfterCount', '')
        ->assertSet('planType', '');
});

it('keeps the plan notes when the transaction type changes in plan mode', function () {
    $plan = modalPlan($this, ['notes' => 'Family plan, shared with Sam']);

    modalEditing($this, $plan)
        ->assertSet('notes', 'Family plan, shared with Sam')
        ->set('transactionType', 'income')
        ->assertSet('notes', 'Family plan, shared with Sam')
        ->call('save')
        ->assertHasNoErrors();

    expect($plan->fresh()->notes)->toBe('Family plan, shared with Sam');
});

it('keeps a plan merchant after its merchant was merged into another', function () {
    $kept = Merchant::factory()->for($this->user)->create(['name' => 'Netflix']);
    $merged = Merchant::factory()->for($this->user)->create(['name' => 'Netflix AU']);
    MerchantAlias::query()->create(['user_id' => $this->user->id, 'merchant_key' => $merged->merchant_key, 'merchant_id' => $kept->id]);
    $merged->delete();
    $plan = modalPlan($this, ['merchant_key' => $merged->merchant_key]);

    modalEditing($this, $plan)
        ->assertSet('merchantId', $kept->id)
        ->set('autopay', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($plan->fresh()->merchant_key)->toBe($kept->merchant_key);
});

it('does not copy a payment link to plans that also have no description', function () {
    $other = modalPlan($this, ['description' => '']);

    modalNewPlan($this, 'expense', '15')
        ->set('paymentUrl', 'gym.example/pay')
        ->set('copyPaymentUrl', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($other->fresh()->payment_url)->toBeNull();
});

it('does not force an end count on a plan that is no longer an installment', function () {
    $plan = modalPlan($this, ['type' => PlannedTransactionType::Installment, 'until_date' => '2027-06-30']);

    modalEditing($this, $plan)
        ->set('transactionType', 'income')
        ->set('endAfterCount', '4')
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $plan->fresh();

    expect($fresh->type)->toBe(PlannedTransactionType::Income)
        ->and($fresh->end_after_count)->toBeNull()
        ->and($fresh->until_date?->toDateString())->toBe('2027-06-30');
});

it('starts a plan made from a month end entry on the next month end', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10'));
    $transaction = Transaction::factory()->for($this->user)->for($this->account)->manual()->create([
        'amount' => 1200,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-01-31',
    ]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('mode', 'plan')
        ->set('frequency', 'every-month')
        ->assertSet('date', '2026-03-31');
});

it('offers only the fixed cadences when planning', function () {
    modalNewPlan($this)
        ->assertSeeHtml('value="every-month"')
        ->assertDontSeeHtml('value="semimonthly"')
        ->assertDontSeeHtml('value="every-n-weeks"');
});

it('fills a plan start date that passes its own rule for a user west of the server', function () {
    useAppTimezone('Australia/Brisbane');
    $this->travelTo(CarbonImmutable::parse('2026-03-10 00:30:00', 'Australia/Brisbane'));
    $perth = User::factory()->withTimezone('Australia/Perth')->create();
    $account = Account::factory()->for($perth)->create();
    $transaction = Transaction::factory()->for($perth)->for($account)->manual()->create([
        'amount' => 1200,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-03-03',
    ]);

    Livewire::actingAs($perth)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->set('mode', 'plan')
        ->set('frequency', 'every-week')
        ->assertSet('date', '2026-03-10')
        ->set('descriptionInput', '12 Gym')
        ->call('save')
        ->assertHasNoErrors();

    expect(PlannedTransaction::query()->where('user_id', $perth->id)->sole()->start_date->toDateString())->toBe('2026-03-10');
});

it('shows a plan on a custom cadence as kept as is and keeps its schedule when saved', function () {
    $plan = modalPlan($this, ['frequency' => RecurrenceFrequency::EveryNMonths, 'recurrence_interval' => 5]);

    modalEditing($this, $plan)
        ->assertSeeHtml('value="every-n-months"')
        ->assertSee('Custom schedule (kept as is)')
        ->set('autopay', true)
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $plan->fresh();

    expect($fresh->frequency)->toBe(RecurrenceFrequency::EveryNMonths)
        ->and($fresh->recurrence_interval)->toBe(5);
});

it('clears the interval and days when a custom cadence is changed to a fixed one', function () {
    $plan = modalPlan($this, ['frequency' => RecurrenceFrequency::Semimonthly, 'days_of_month' => [15, 'last']]);

    modalEditing($this, $plan)->set('frequency', 'every-month')->call('save')->assertHasNoErrors();

    $fresh = $plan->fresh();

    expect($fresh->frequency)->toBe(RecurrenceFrequency::EveryMonth)
        ->and($fresh->days_of_month)->toBeNull()
        ->and($fresh->recurrence_interval)->toBe(1);
});
