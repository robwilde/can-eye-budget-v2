<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\PlanAmountStrategy;
use App\Enums\PlannedTransactionType;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Livewire\PlannedTransactionManager;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\MerchantAlias;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->primaryAccount = Account::factory()->for($this->user)->create(['name' => 'Primary Account']);
    $this->secondaryAccount = Account::factory()->for($this->user)->create(['name' => 'Secondary Account']);
    $this->user->update(['primary_account_id' => $this->primaryAccount->id]);
    $this->category = Category::factory()->for($this->user)->create();
});

test('mount defaults account id to the primary account', function () {
    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->assertSet('accountId', $this->primaryAccount->id);
});

test('lists the selected accounts planned transactions and excludes another accounts plans', function () {
    PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'description' => 'Primary Netflix',
    ]);

    PlannedTransaction::factory()->for($this->user)->for($this->secondaryAccount)->create([
        'description' => 'Secondary Spotify',
    ]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->set('accountId', $this->secondaryAccount->id)
        ->assertSee('Secondary Spotify')
        ->assertDontSee('Primary Netflix');
});

test('matched count equals the number of linked transactions', function () {
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'description' => 'Gym Membership',
    ]);

    Transaction::factory()->count(2)
        ->for($this->user)
        ->for($this->primaryAccount)
        ->create(['planned_transaction_id' => $plan->id]);

    Transaction::factory()->for($this->user)->for($this->primaryAccount)->create();

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->assertSee('Gym Membership')
        ->assertSee('2 matches');
});

test('save updates amount frequency category direction and until date on the plan', function () {
    $newCategory = Category::factory()->for($this->user)->create();

    $plan = PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'amount' => 1099,
        'frequency' => RecurrenceFrequency::EveryMonth,
        'category_id' => $this->category->id,
        'direction' => TransactionDirection::Debit,
        'until_date' => null,
        'is_active' => true,
    ]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->call('openEdit', $plan->id)
        ->assertSet('editingId', $plan->id)
        ->set('amount', '25.50')
        ->set('frequency', RecurrenceFrequency::Every2Weeks->value)
        ->set('categoryId', (string) $newCategory->id)
        ->set('direction', TransactionDirection::Credit->value)
        ->set('untilDate', '2026-09-30')
        ->set('isActive', false)
        ->call('save');

    $freshPlan = $plan->fresh();

    expect($freshPlan)->not->toBeNull()
        ->and($freshPlan->amount)->toBe(2550)
        ->and($freshPlan->frequency)->toBe(RecurrenceFrequency::Every2Weeks)
        ->and($freshPlan->category_id)->toBe($newCategory->id)
        ->and($freshPlan->direction)->toBe(TransactionDirection::Credit)
        ->and($freshPlan->until_date?->toDateString())->toBe('2026-09-30')
        ->and($freshPlan->is_active)->toBeFalse();
});

test('toggleActive flips is_active', function () {
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'is_active' => true,
    ]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->call('toggleActive', $plan->id);

    expect($plan->fresh()->is_active)->toBeFalse();
});

test('delete removes the plan', function () {
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'description' => 'Delete Me',
    ]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->call('confirmDelete', $plan->id)
        ->call('delete');

    expect(PlannedTransaction::query()->find($plan->id))->toBeNull();
});

test('pay cycle income plans cannot be opened for edit', function () {
    $incomePlan = PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'description' => 'Salary Income',
        'amount' => 320000,
        'is_pay_cycle_income' => true,
        'direction' => TransactionDirection::Credit,
    ]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->call('openEdit', $incomePlan->id)
        ->assertSet('showEditModal', false)
        ->assertSet('editingId', null)
        ->assertSet('amount', '')
        ->assertSet('direction', '')
        ->assertSet('frequency', '');

    $freshPlan = $incomePlan->fresh();

    expect($freshPlan)->not->toBeNull()
        ->and($freshPlan->description)->toBe('Salary Income')
        ->and($freshPlan->amount)->toBe(320000)
        ->and($freshPlan->is_pay_cycle_income)->toBeTrue();
});

test('pay cycle income plans cannot be deleted', function () {
    $incomePlan = PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'description' => 'Protected Income',
        'amount' => 410000,
        'is_pay_cycle_income' => true,
        'direction' => TransactionDirection::Credit,
    ]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->call('confirmDelete', $incomePlan->id)
        ->assertSet('showDeleteModal', false)
        ->assertSet('deletingId', null)
        ->set('deletingId', $incomePlan->id)
        ->call('delete');

    $freshPlan = $incomePlan->fresh();

    expect($freshPlan)->not->toBeNull()
        ->and($freshPlan->description)->toBe('Protected Income')
        ->and($freshPlan->amount)->toBe(410000)
        ->and($freshPlan->is_pay_cycle_income)->toBeTrue();
});

test('pay cycle income plans cannot be saved through a forced edit', function () {
    $incomePlan = PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'description' => 'Forced Income',
        'amount' => 500000,
        'frequency' => RecurrenceFrequency::EveryMonth,
        'direction' => TransactionDirection::Credit,
        'is_pay_cycle_income' => true,
    ]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->set('editingId', $incomePlan->id)
        ->set('amount', '12.34')
        ->set('frequency', RecurrenceFrequency::EveryWeek->value)
        ->set('direction', TransactionDirection::Debit->value)
        ->call('save')
        ->assertSet('showEditModal', false)
        ->assertSet('editingId', null);

    $fresh = $incomePlan->fresh();

    expect($fresh->amount)->toBe(500000)
        ->and($fresh->frequency)->toBe(RecurrenceFrequency::EveryMonth)
        ->and($fresh->direction)->toBe(TransactionDirection::Credit)
        ->and($fresh->is_pay_cycle_income)->toBeTrue();
});

test('rejects a sub-cent amount on save', function () {
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'amount' => 2500,
    ]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->call('openEdit', $plan->id)
        ->set('amount', '0.004')
        ->call('save')
        ->assertHasErrors(['amount']);

    expect($plan->fresh()->amount)->toBe(2500);
});

test('rejects an amount with more than two decimal places', function () {
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'amount' => 2500,
    ]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->call('openEdit', $plan->id)
        ->set('amount', '10.005')
        ->call('save')
        ->assertHasErrors(['amount']);

    expect($plan->fresh()->amount)->toBe(2500);
});

test('renders formatted money frequency and the income badge', function () {
    PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'description' => 'Internet Bill',
        'amount' => 8999,
        'frequency' => RecurrenceFrequency::EveryMonth,
        'is_pay_cycle_income' => false,
    ]);
    PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create([
        'description' => 'Salary',
        'is_pay_cycle_income' => true,
    ]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->assertSee('Internet Bill')
        ->assertSee('$89.99')
        ->assertSee('Every month')
        ->assertSee('Income')
        ->assertSee('Managed from pay cycle');
});

test('shows an empty state when the account has no plans', function () {
    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->assertSee('No planned transactions for this account yet.');
});

test("a plan never keeps another user's category", function () {
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create(['category_id' => $this->category->id]);
    $theirs = Category::factory()->create();

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->call('openEdit', $plan->id)
        ->set('categoryId', (string) $theirs->id)
        ->call('save');

    expect($plan->fresh()->category_id)->not->toBe($theirs->id);
});

test('save records a recent pick only when the user changed the category by hand', function () {
    $newCategory = Category::factory()->for($this->user)->create();
    $plan = PlannedTransaction::factory()->for($this->user)->for($this->primaryAccount)->create(['category_id' => $this->category->id]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->call('openEdit', $plan->id)
        ->call('save');

    expect($this->user->fresh()->preferences->recentCategoryIds)->toBe([]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->call('openEdit', $plan->id)
        ->set('categoryId', (string) $newCategory->id)
        ->call('save');

    expect($this->user->fresh()->preferences->recentCategoryIds)->toBe([$newCategory->id]);
});

function managerPlan(object $test, array $attributes = []): PlannedTransaction
{
    return PlannedTransaction::factory()->for($test->user)->for($test->primaryAccount)->create($attributes);
}

function managerEditing(object $test, PlannedTransaction $plan): Testable
{
    return Livewire::actingAs($test->user)
        ->test(PlannedTransactionManager::class)
        ->call('openEdit', $plan->id);
}

it('adds https to a payment link without a scheme', function () {
    $plan = managerPlan($this);

    managerEditing($this, $plan)
        ->set('paymentUrl', 'example.com/pay')
        ->call('save')
        ->assertHasNoErrors();

    expect($plan->fresh()->payment_url)->toBe('https://example.com/pay');
});

it('rejects a payment link that is not http or https', function () {
    $plan = managerPlan($this, ['payment_url' => 'https://old.example/pay']);

    managerEditing($this, $plan)
        ->set('paymentUrl', 'javascript:alert(1)')
        ->call('save')
        ->assertHasErrors(['paymentUrl']);

    expect($plan->fresh()->payment_url)->toBe('https://old.example/pay');
});

it('refuses a payment link longer than 2048 characters', function () {
    $plan = managerPlan($this);

    managerEditing($this, $plan)
        ->set('paymentUrl', 'https://example.com/'.str_repeat('a', 2048))
        ->call('save')
        ->assertHasErrors(['paymentUrl']);

    expect($plan->fresh()->payment_url)->toBeNull();
});

it('clears the payment link when it is emptied', function () {
    $plan = managerPlan($this, ['payment_url' => 'https://old.example/pay']);

    managerEditing($this, $plan)->set('paymentUrl', '')->call('save')->assertHasNoErrors();

    expect($plan->fresh()->payment_url)->toBeNull();
});

it('copies a payment link only to the users own matching plans', function () {
    $merchant = Merchant::factory()->for($this->user)->create(['name' => 'Netflix']);
    $otherUser = User::factory()->create();
    $otherAccount = Account::factory()->for($otherUser)->create();

    $plan = managerPlan($this, ['description' => 'Netflix', 'merchant_key' => $merchant->merchant_key]);
    $sameMerchant = managerPlan($this, ['description' => 'Netflix 4K', 'merchant_key' => $merchant->merchant_key]);
    $sameName = managerPlan($this, ['description' => 'Netflix']);
    $unrelated = managerPlan($this, ['description' => 'Gym']);
    $payCycle = managerPlan($this, ['description' => 'Netflix', 'is_pay_cycle_income' => true]);
    $theirs = PlannedTransaction::factory()->for($otherUser)->for($otherAccount)->create([
        'description' => 'Netflix',
        'merchant_key' => $merchant->merchant_key,
    ]);

    managerEditing($this, $plan)
        ->set('paymentUrl', 'netflix.example/pay')
        ->set('copyPaymentUrl', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($plan->fresh()->payment_url)->toBe('https://netflix.example/pay')
        ->and($sameMerchant->fresh()->payment_url)->toBe('https://netflix.example/pay')
        ->and($sameName->fresh()->payment_url)->toBe('https://netflix.example/pay')
        ->and($unrelated->fresh()->payment_url)->toBeNull()
        ->and($payCycle->fresh()->payment_url)->toBeNull()
        ->and($theirs->fresh()->payment_url)->toBeNull();
});

it('leaves other plans alone when copy is not ticked', function () {
    $plan = managerPlan($this, ['description' => 'Netflix']);
    $sameName = managerPlan($this, ['description' => 'Netflix']);

    managerEditing($this, $plan)->set('paymentUrl', 'netflix.example/pay')->call('save');

    expect($sameName->fresh()->payment_url)->toBeNull();
});

it('does not copy a refused payment link', function () {
    $plan = managerPlan($this, ['description' => 'Netflix']);
    $sameName = managerPlan($this, ['description' => 'Netflix']);

    managerEditing($this, $plan)
        ->set('paymentUrl', 'javascript:alert(1)')
        ->set('copyPaymentUrl', true)
        ->call('save')
        ->assertHasErrors(['paymentUrl']);

    expect($sameName->fresh()->payment_url)->toBeNull();
});

it('forces end after count when an installment gets a payment count', function () {
    $plan = managerPlan($this, [
        'type' => PlannedTransactionType::Installment,
        'until_date' => '2027-06-30',
    ]);

    managerEditing($this, $plan)
        ->assertSet('untilDate', '2027-06-30')
        ->set('endAfterCount', '4')
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $plan->fresh();

    expect($fresh->end_after_count)->toBe(4)
        ->and($fresh->until_date)->toBeNull();
});

it('refuses both an end date and a payment count on a plan that is not an installment', function () {
    $plan = managerPlan($this, ['type' => PlannedTransactionType::Bill]);

    managerEditing($this, $plan)
        ->set('untilDate', '2027-06-30')
        ->set('endAfterCount', '4')
        ->call('save')
        ->assertHasErrors(['untilDate']);

    expect($plan->fresh()->end_after_count)->toBeNull();
});

it('ends a bill after a payment count and clears the count when it is emptied', function () {
    $plan = managerPlan($this);

    managerEditing($this, $plan)->set('endAfterCount', '12')->call('save')->assertHasNoErrors();
    expect($plan->fresh()->end_after_count)->toBe(12);

    managerEditing($this, $plan->fresh())->assertSet('endAfterCount', '12')->set('endAfterCount', '')->call('save');
    expect($plan->fresh()->end_after_count)->toBeNull();
});

it('refuses an end count above 600', function () {
    $plan = managerPlan($this);

    managerEditing($this, $plan)
        ->set('endAfterCount', '601')
        ->call('save')
        ->assertHasErrors(['endAfterCount']);

    expect($plan->fresh()->end_after_count)->toBeNull();
});

it('refuses an end count below 1 or that is not a whole number', function (string $count) {
    $plan = managerPlan($this);

    managerEditing($this, $plan)->set('endAfterCount', $count)->call('save')->assertHasErrors(['endAfterCount']);

    expect($plan->fresh()->end_after_count)->toBeNull();
})->with(['0', '-3', '2.5', 'abc']);

it('accepts an end count of 600', function () {
    $plan = managerPlan($this);

    managerEditing($this, $plan)->set('endAfterCount', '600')->call('save')->assertHasNoErrors();

    expect($plan->fresh()->end_after_count)->toBe(600);
});

it('refuses a plan amount with more than two decimal places', function () {
    $plan = managerPlan($this, ['amount' => 5000]);

    managerEditing($this, $plan)
        ->set('amount', '10.123')
        ->call('save')
        ->assertHasErrors(['amount']);

    expect($plan->fresh()->amount)->toBe(5000);
});

it('audits saving a plan with the new series fields', function () {
    $plan = managerPlan($this);

    managerEditing($this, $plan)
        ->set('planType', 'subscription')
        ->set('paymentUrl', 'example.com/pay')
        ->set('autopay', true)
        ->set('endAfterCount', '6')
        ->call('save')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->where('action', 'livewire.planned-transaction-manager.save')->sole();

    expect($event->user_id)->toBe($this->user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success)
        ->and($plan->fresh()->autopay)->toBeTrue();
});

it('audits a refused series save as a failure', function () {
    $plan = managerPlan($this);

    managerEditing($this, $plan)->set('paymentUrl', 'javascript:alert(1)')->call('save');

    expect(AuditEvent::query()->where('action', 'livewire.planned-transaction-manager.save')->sole()->outcome)
        ->toBe(AuditOutcome::Failure);
});

it('saves the chosen type, merchant, notes and series settings', function () {
    $merchant = Merchant::factory()->for($this->user)->create(['name' => 'Spotify']);
    $plan = managerPlan($this);

    managerEditing($this, $plan)
        ->set('planType', 'subscription')
        ->set('merchantId', (string) $merchant->id)
        ->set('notes', 'Family plan')
        ->set('autopay', true)
        ->set('renewalOn', '2027-01-01')
        ->set('trialEndsOn', '2026-12-01')
        ->set('cancelledOn', '2027-02-01')
        ->set('reminderDays', '5')
        ->set('graceDays', '1')
        ->set('tolerancePercent', '12.34')
        ->set('matchEarlyDays', '4')
        ->set('matchLateDays', '9')
        ->set('amountStrategy', 'last_paid')
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $plan->fresh();

    expect($fresh->type)->toBe(PlannedTransactionType::Subscription)
        ->and($fresh->merchant_key)->toBe($merchant->merchant_key)
        ->and($fresh->notes)->toBe('Family plan')
        ->and($fresh->autopay)->toBeTrue()
        ->and($fresh->renewal_on->toDateString())->toBe('2027-01-01')
        ->and($fresh->trial_ends_on->toDateString())->toBe('2026-12-01')
        ->and($fresh->cancelled_on->toDateString())->toBe('2027-02-01')
        ->and($fresh->reminder_days)->toBe(5)
        ->and($fresh->grace_days)->toBe(1)
        ->and($fresh->amount_tolerance_bps)->toBe(1234)
        ->and($fresh->match_early_days)->toBe(4)
        ->and($fresh->match_late_days)->toBe(9)
        ->and($fresh->amount_strategy)->toBe(PlanAmountStrategy::LastPaid);
});

it('loads the saved series settings into the edit form', function () {
    $plan = managerPlan($this, [
        'type' => PlannedTransactionType::Subscription,
        'payment_url' => 'https://example.com/pay',
        'autopay' => true,
        'end_after_count' => 9,
        'amount_tolerance_bps' => 750,
        'notes' => 'Shared',
    ]);

    managerEditing($this, $plan)
        ->assertSet('planType', 'subscription')
        ->assertSet('paymentUrl', 'https://example.com/pay')
        ->assertSet('autopay', true)
        ->assertSet('endAfterCount', '9')
        ->assertSet('tolerancePercent', '7.5')
        ->assertSet('notes', 'Shared');
});

it('refuses out of range tuning values', function (string $field, string $value) {
    $plan = managerPlan($this);

    managerEditing($this, $plan)->set($field, $value)->call('save')->assertHasErrors([$field]);

    expect($plan->fresh()->reminder_days)->toBe(3);
})->with([
    ['reminderDays', '-1'],
    ['reminderDays', '366'],
    ['graceDays', 'x'],
    ['tolerancePercent', '101'],
    ['tolerancePercent', '7.555'],
    ['matchEarlyDays', '91'],
    ['matchLateDays', '2.5'],
    ['amountStrategy', 'median'],
]);

it('retypes a plan whose direction changes to the direction default', function () {
    $plan = managerPlan($this, ['type' => PlannedTransactionType::Subscription, 'direction' => TransactionDirection::Debit]);

    managerEditing($this, $plan)->set('direction', 'credit')->call('save')->assertHasNoErrors();

    expect($plan->fresh()->type)->toBe(PlannedTransactionType::Income);
});

it('types a credit plan as income and a debit plan as the chosen type', function () {
    $credit = managerPlan($this, ['direction' => TransactionDirection::Credit]);
    $debit = managerPlan($this, ['direction' => TransactionDirection::Debit]);

    managerEditing($this, $credit)->set('planType', 'income')->call('save');
    managerEditing($this, $debit)->set('planType', 'installment')->call('save');

    expect($credit->fresh()->type)->toBe(PlannedTransactionType::Income)
        ->and($debit->fresh()->type)->toBe(PlannedTransactionType::Installment);
});

it('keeps the transfer type locked on a plan with a destination', function () {
    $plan = managerPlan($this, ['transfer_to_account_id' => $this->secondaryAccount->id]);

    managerEditing($this, $plan)
        ->assertSet('planType', 'transfer')
        ->assertSee('A plan with a destination account is always a transfer.')
        ->set('planType', 'bill')
        ->set('notes', 'Moved')
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $plan->fresh();

    expect($fresh->type)->toBe(PlannedTransactionType::Transfer)
        ->and($fresh->transfer_to_account_id)->toBe($this->secondaryAccount->id)
        ->and($fresh->notes)->toBe('Moved');
});

it('offers transfer only on a plan with a destination', function () {
    $plain = managerPlan($this);
    $transfer = managerPlan($this, ['transfer_to_account_id' => $this->secondaryAccount->id]);

    managerEditing($this, $plain)->assertDontSeeHtml('value="transfer"');
    managerEditing($this, $transfer)->assertSeeHtml('value="transfer"');
});

it('refuses a merchant that belongs to another user', function () {
    $foreign = Merchant::factory()->for(User::factory()->create())->create();
    $plan = managerPlan($this);

    managerEditing($this, $plan)->set('merchantId', (string) $foreign->id)->call('save')->assertHasErrors(['merchantId']);

    expect($plan->fresh()->merchant_key)->toBeNull();
});

it('does not open another users plan for editing', function () {
    $theirs = PlannedTransaction::factory()->for(User::factory()->create())->create();

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->call('openEdit', $theirs->id)
        ->assertSet('showEditModal', false)
        ->assertSet('editingId', null);
});

it('renders a stored payment link as a safe external link', function () {
    managerPlan($this, ['description' => 'Water', 'payment_url' => 'https://water.example/pay?a=1&b=2']);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->assertSeeHtml('href="https://water.example/pay?a=1&amp;b=2"')
        ->assertSeeHtml('rel="noopener noreferrer"')
        ->assertSeeHtml('target="_blank"');
});

it('does not render a stored link that is not http or https', function () {
    $plan = managerPlan($this, ['description' => 'Sneaky']);
    DB::table('planned_transactions')->where('id', $plan->id)->update(['payment_url' => 'javascript:alert(1)']);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->assertSee('Sneaky')
        ->assertDontSeeHtml('javascript:alert')
        ->assertDontSeeHtml('data-testid="plan-payment-link"');
});

it('keeps a plan merchant after its merchant was merged into another', function () {
    $kept = Merchant::factory()->for($this->user)->create(['name' => 'Netflix']);
    $merged = Merchant::factory()->for($this->user)->create(['name' => 'Netflix AU']);
    MerchantAlias::query()->create(['user_id' => $this->user->id, 'merchant_key' => $merged->merchant_key, 'merchant_id' => $kept->id]);
    $merged->delete();
    $plan = managerPlan($this, ['merchant_key' => $merged->merchant_key]);

    managerEditing($this, $plan)
        ->assertSet('merchantId', $kept->id)
        ->set('autopay', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($plan->fresh()->merchant_key)->toBe($kept->merchant_key);
});

it('refuses both an end date and a count once an installment becomes income', function () {
    $plan = managerPlan($this, ['type' => PlannedTransactionType::Installment, 'end_after_count' => 12]);

    managerEditing($this, $plan)
        ->set('direction', 'credit')
        ->set('untilDate', '2027-01-01')
        ->call('save')
        ->assertHasErrors(['untilDate']);

    expect($plan->fresh()->type)->toBe(PlannedTransactionType::Installment);
});

it('does not copy a payment link to plans that also have no description', function () {
    $plan = managerPlan($this, ['description' => '']);
    $other = managerPlan($this, ['description' => '']);

    managerEditing($this, $plan)
        ->set('paymentUrl', 'gym.example/pay')
        ->set('copyPaymentUrl', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($other->fresh()->payment_url)->toBeNull();
});

it('offers credit types as soon as the direction changes', function () {
    $plan = managerPlan($this);

    managerEditing($this, $plan)
        ->assertSeeHtml('value="installment"')
        ->set('direction', 'credit')
        ->assertDontSeeHtml('value="installment"')
        ->assertSeeHtml('value="income"');
});

it('lists the type under its heading', function () {
    managerPlan($this, ['description' => 'Sub', 'type' => PlannedTransactionType::Subscription]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->assertSeeInOrder(['Description', 'Type', 'Amount', 'Direction'])
        ->assertSeeHtmlInOrder(['data-testid="plan-type"', 'Subscription', 'Debit']);
});

it('works out the next occurrence on the users local date', function () {
    useAppTimezone('Australia/Brisbane');
    $this->travelTo(CarbonImmutable::parse('2026-03-01 22:00:00', 'Australia/Brisbane'));
    $auckland = User::factory()->withTimezone('Pacific/Auckland')->create();
    $account = Account::factory()->for($auckland)->create();
    $auckland->update(['primary_account_id' => $account->id]);
    PlannedTransaction::factory()->monthly()->for($auckland)->for($account)->create([
        'description' => 'Rates',
        'start_date' => '2026-03-01',
    ]);

    Livewire::actingAs($auckland)
        ->test(PlannedTransactionManager::class)
        ->assertSee('2026-04-01')
        ->assertDontSee('2026-03-01');
});

it('shows the interval label of a plan on a new cadence', function () {
    managerPlan($this, [
        'description' => 'Insurance',
        'frequency' => RecurrenceFrequency::EveryNMonths,
        'recurrence_interval' => 5,
    ]);

    Livewire::actingAs($this->user)
        ->test(PlannedTransactionManager::class)
        ->assertSee('Every 5 months');
});

it('offers every cadence the picker can express in the edit form', function () {
    $plan = managerPlan($this);

    managerEditing($this, $plan)
        ->assertSeeHtml('value="every-month"')
        ->assertSeeHtml('value="semimonthly"')
        ->assertSeeHtml('value="every-n-months"')
        ->assertDontSeeHtml('value="two-on-two-off"');
});

it('shows an unsupported frequency as custom and keeps it on save', function () {
    $plan = managerPlan($this, ['frequency' => RecurrenceFrequency::TwoOnTwoOff]);

    managerEditing($this, $plan)
        ->assertSeeHtml('value="two-on-two-off"')
        ->assertSee('Custom')
        ->set('autopay', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($plan->fresh()->frequency)->toBe(RecurrenceFrequency::TwoOnTwoOff);
});

it('keeps the interval and days when a cadence that uses them is picked and clears them for one that does not', function () {
    $plan = managerPlan($this, ['frequency' => RecurrenceFrequency::EveryNMonths, 'recurrence_interval' => 5, 'days_of_month' => [10]]);

    managerEditing($this, $plan)->assertSet('recurrenceInterval', '5')->assertSet('daysOfMonth', '10')->set('frequency', 'every-month')->call('save')->assertHasNoErrors();

    expect($plan->fresh()->days_of_month)->toBe([10])->and($plan->fresh()->recurrence_interval)->toBe(1);

    managerEditing($this, $plan->fresh())->set('frequency', 'every-week')->call('save')->assertHasNoErrors();

    expect($plan->fresh()->days_of_month)->toBeNull()->and($plan->fresh()->recurrence_interval)->toBe(1);
});
