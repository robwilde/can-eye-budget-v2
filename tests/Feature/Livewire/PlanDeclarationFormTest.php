<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\PlannedTransactionStatus;
use App\Enums\PlannedTransactionType;
use App\Enums\PlanOrigin;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Livewire\PlanDeclarationForm;
use App\Livewire\PlannedTransactionManager;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\PlannedTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->user->update(['primary_account_id' => $this->account->id]);
});

function declareForm(object $test, string $kind = 'bill')
{
    return Livewire::actingAs($test->user)->test(PlanDeclarationForm::class)->call('open', $kind);
}

function declareFill($form, string $description, string $amount, string $date = '2026-03-15')
{
    return $form->set('description', $description)->set('amount', $amount)->set('startDate', $date);
}

it('stores a declared income as a credit plan every two weeks', function () {
    declareFill(declareForm($this, 'income'), 'Salary', '2000', '2026-03-20')->call('save')->assertHasNoErrors();

    $plan = PlannedTransaction::query()->sole();

    expect($plan->amount)->toBe(200000)
        ->and($plan->direction)->toBe(TransactionDirection::Credit)
        ->and($plan->type)->toBe(PlannedTransactionType::Income)
        ->and($plan->frequency)->toBe(RecurrenceFrequency::Every2Weeks)
        ->and($plan->origin)->toBe(PlanOrigin::Declared)
        ->and($plan->status)->toBe(PlannedTransactionStatus::Active);
});

it('creates an active monthly bill due on the first due day', function () {
    $form = declareForm($this);

    expect($form->get('frequency'))->toBe('every-month')
        ->and($form->get('accountId'))->toBe($this->account->id);

    declareFill($form, 'Rent', '100', '2026-03-15')->call('save')->assertHasNoErrors();

    $plan = PlannedTransaction::query()->sole();

    expect($plan->direction)->toBe(TransactionDirection::Debit)
        ->and($plan->type)->toBe(PlannedTransactionType::Bill)
        ->and($plan->origin)->toBe(PlanOrigin::Declared)
        ->and($plan->status)->toBe(PlannedTransactionStatus::Active)
        ->and($plan->amount)->toBe(10000)
        ->and($plan->occurrencesBetween(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-06-30'))->map(fn (CarbonImmutable $date): string => $date->toDateString())->all())
        ->toBe(['2026-03-15', '2026-04-15', '2026-05-15', '2026-06-15']);
});

it('refuses a duplicate bill but accepts a second price tier', function () {
    declareFill(declareForm($this), 'Rent', '100')->call('save')->assertHasNoErrors();

    declareFill(declareForm($this), 'rent', '100')->call('save')
        ->assertHasErrors(['description'])
        ->assertSee('already exists');

    expect(PlannedTransaction::query()->count())->toBe(1);

    declareFill(declareForm($this), 'Rent', '60')->call('save')->assertHasNoErrors();

    expect(PlannedTransaction::query()->count())->toBe(2);
});

it('shows an account error for an account the user does not own', function () {
    $foreign = Account::factory()->for(User::factory()->create())->create();

    declareFill(declareForm($this), 'Rent', '100')->set('accountId', $foreign->id)->call('save')
        ->assertHasErrors(['accountId'])
        ->assertSee('Choose one of your own accounts.');

    expect(PlannedTransaction::query()->count())->toBe(0);
});

it('shows an account error when no account is chosen', function () {
    declareFill(declareForm($this), 'Rent', '100')->set('accountId', '')->call('save')
        ->assertHasErrors(['accountId'])
        ->assertSee('Choose the account this is paid from.');

    expect(PlannedTransaction::query()->count())->toBe(0);
});

it('refuses an untracked account', function () {
    $untracked = Account::factory()->for($this->user)->untracked()->create();

    declareFill(declareForm($this), 'Rent', '100')->set('accountId', $untracked->id)->call('save')->assertHasErrors(['accountId']);

    expect(PlannedTransaction::query()->count())->toBe(0);
});

it('audits declaring a bill', function () {
    declareFill(declareForm($this), 'Rent', '100')->call('save')->assertHasNoErrors();

    $event = AuditEvent::query()->where('action', 'livewire.plan-declaration-form.save')->sole();

    expect($event->user_id)->toBe($this->user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success);
});

it('audits a refused declaration as a failure', function () {
    declareFill(declareForm($this), 'Rent', '100')->call('save');
    declareFill(declareForm($this), 'Rent', '100')->call('save');

    expect(AuditEvent::query()->where('action', 'livewire.plan-declaration-form.save')->orderBy('id')->pluck('outcome')->all())
        ->toBe([AuditOutcome::Success, AuditOutcome::Failure]);
});

it('keeps the optional fields', function () {
    $category = Category::factory()->for($this->user)->create();
    $merchant = Merchant::factory()->for($this->user)->create();

    declareFill(declareForm($this), 'Internet', '79.95')
        ->set('categoryId', $category->id)
        ->set('merchantId', $merchant->id)
        ->set('paymentUrl', 'isp.example/pay')
        ->set('autopay', true)
        ->set('notes', 'Annual plan')
        ->call('save')->assertHasNoErrors();

    $plan = PlannedTransaction::query()->sole();

    expect($plan->amount)->toBe(7995)
        ->and($plan->category_id)->toBe($category->id)
        ->and($plan->merchant_key)->toBe($merchant->merchant_key)
        ->and($plan->payment_url)->toBe('https://isp.example/pay')
        ->and($plan->autopay)->toBeTrue()
        ->and($plan->notes)->toBe('Annual plan');
});

it('refuses another users category and merchant', function () {
    $other = User::factory()->create();
    $category = Category::factory()->for($other)->create();
    $merchant = Merchant::factory()->for($other)->create();

    declareFill(declareForm($this), 'Rent', '100')->set('categoryId', $category->id)->call('save')->assertHasErrors(['categoryId']);
    declareFill(declareForm($this), 'Rent', '100')->set('merchantId', $merchant->id)->call('save')->assertHasErrors(['merchantId']);

    expect(PlannedTransaction::query()->count())->toBe(0);
});

it('requires a description, a positive amount with at most two decimals, a date and a cadence', function () {
    declareFill(declareForm($this), '', '')->set('frequency', '')->set('startDate', '')->call('save')
        ->assertHasErrors(['description', 'amount', 'startDate', 'frequency']);

    foreach (['0', '-5', '1.234', 'abc'] as $amount) {
        declareFill(declareForm($this), 'Rent', $amount)->call('save')->assertHasErrors(['amount']);
    }

    declareFill(declareForm($this), 'Rent', '10', '15/03/2026')->call('save')->assertHasErrors(['startDate']);

    expect(PlannedTransaction::query()->count())->toBe(0);
});

it('refuses a payment link that is not a web address', function () {
    declareFill(declareForm($this), 'Rent', '100')->set('paymentUrl', 'javascript:alert(1)')->call('save')->assertHasErrors(['paymentUrl']);

    expect(PlannedTransaction::query()->count())->toBe(0);
});

it('does not see another users plan as a duplicate', function () {
    $other = User::factory()->create();
    PlannedTransaction::factory()->for($other)->create(['account_id' => $this->account->id, 'description' => 'Rent', 'amount' => 10000, 'direction' => TransactionDirection::Debit]);

    declareFill(declareForm($this), 'Rent', '100')->call('save')->assertHasNoErrors();

    expect(PlannedTransaction::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

it('opens from the add bill and add income buttons of the plans page and shows the new plan', function () {
    $second = Account::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)
        ->assertSeeHtml('data-testid="add-bill"')
        ->assertSeeHtml('data-testid="add-income"')
        ->assertSet('accountId', $this->account->id)
        ->dispatch('plan-declared', accountId: $second->id)
        ->assertSet('accountId', $second->id);
});

it('refuses tampered settings the form never shows', function (string $property, string $value) {
    declareFill(declareForm($this), 'Rent', '100')->set($property, $value)->call('save')->assertHasErrors([$property]);

    expect(PlannedTransaction::query()->count())->toBe(0);
})->with([
    'amount strategy' => ['amountStrategy', 'x'],
    'renewal date' => ['renewalOn', 'x'],
    'negative tolerance' => ['tolerancePercent', '-1'],
    'huge match window' => ['matchLateDays', '60000'],
    'plan type' => ['planType', 'x'],
]);

it('refuses an amount too large to store', function () {
    declareFill(declareForm($this), 'Rent', '100000000000000000')->call('save')->assertHasErrors(['amount']);

    expect(PlannedTransaction::query()->count())->toBe(0);
});

it('refuses a description of only non-breaking spaces', function () {
    declareFill(declareForm($this), "\u{00A0}\u{00A0}", '100')->call('save')->assertHasErrors(['description']);

    expect(PlannedTransaction::query()->count())->toBe(0);
});

it('refuses to declare a plan while recurring is switched off', function () {
    $this->user->updatePreferences(['recurringEnabled' => false]);

    declareFill(declareForm($this), 'Gym', '20')->call('save')->assertSet('showModal', false);

    expect(PlannedTransaction::query()->count())->toBe(0);
});
