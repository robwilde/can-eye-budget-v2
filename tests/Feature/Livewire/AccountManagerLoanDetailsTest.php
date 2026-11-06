<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountClass;
use App\Enums\AuditOutcome;
use App\Enums\ImportSource;
use App\Enums\LoanRateType;
use App\Enums\ValuationKind;
use App\Livewire\AccountManager;
use App\Models\Account;
use App\Models\AccountDetail;
use App\Models\AccountValuation;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\AccountValuationService;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-06-15 10:00:00');
});

function addLoan(User $user, string $balance, string $principal, string $startDate, AccountClass $type = AccountClass::Loan): Testable
{
    return Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openAddModal')
        ->set('name', 'Car Loan')
        ->set('type', $type->value)
        ->set('balance', $balance)
        ->set('originalPrincipal', $principal)
        ->set('startDate', $startDate)
        ->set('rateType', LoanRateType::Fixed->value)
        ->set('rate', '6')
        ->set('termMonths', '60')
        ->call('save');
}

it('records opening and current balances for a new loan', function () {
    $user = User::factory()->create();

    addLoan($user, '15000.00', '20000.00', '2024-06-15')->assertHasNoErrors();

    $account = Account::query()->where('user_id', $user->id)->sole();
    $opening = $account->valuations()->where('kind', ValuationKind::OpeningBalance)->sole();
    $today = $account->valuations()->whereDate('valued_on', '2026-06-15')->sole();

    expect($opening->balance)->toBe(-2000000)
        ->and($opening->valued_on->toDateString())->toBe('2024-06-15')
        ->and($today->kind)->toBe(ValuationKind::Reconciliation)
        ->and($today->balance)->toBe(-1500000)
        ->and($account->valuations()->count())->toBe(2)
        ->and($account->fresh()->balance)->toBe(-1500000)
        ->and($account->detail->original_principal)->toBe(2000000)
        ->and($account->detail->rate_type)->toBe(LoanRateType::Fixed)
        ->and($account->detail->term_months)->toBe(60);
    expectBalanceHistoryConsistent($account);
});

it('stores the amount owed on a new loan as negative however it is typed', function (string $typed) {
    $user = User::factory()->create();

    addLoan($user, $typed, '20000.00', '2024-06-15', AccountClass::Mortgage)->assertHasNoErrors();

    expect(Account::query()->where('user_id', $user->id)->sole()->balance)->toBe(-1500000);
})->with(['positive' => ['15000.00'], 'negative' => ['-15000.00']]);

it('rejects a loan start date in the future', function () {
    $user = User::factory()->create();

    addLoan($user, '15000.00', '20000.00', '2026-06-16')->assertHasErrors('startDate');

    expect(Account::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(AccountDetail::query()->count())->toBe(0)
        ->and(AccountValuation::query()->count())->toBe(0);
});

it('records only the opening balance when a loan starts today', function () {
    $user = User::factory()->create();

    addLoan($user, '20000.00', '20000.00', '2026-06-15')->assertHasNoErrors();

    $account = Account::query()->where('user_id', $user->id)->sole();
    $opening = $account->valuations()->sole();

    expect($opening->kind)->toBe(ValuationKind::OpeningBalance)
        ->and($opening->balance)->toBe(-2000000)
        ->and($opening->valued_on->toDateString())->toBe('2026-06-15')
        ->and($account->fresh()->balance)->toBe(-2000000);
});

it('rejects a loan starting today whose balance differs from its principal', function () {
    $user = User::factory()->create();

    addLoan($user, '15000.00', '20000.00', '2026-06-15')->assertHasErrors('balance');

    expect(Account::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(AccountDetail::query()->count())->toBe(0);
});

it('rejects a loan principal too large to schedule', function () {
    $user = User::factory()->create();

    addLoan($user, '15000.00', '10000000000.00', '2024-06-15')->assertHasErrors('originalPrincipal');

    expect(Account::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('cannot write loan details for another users account', function () {
    $user = User::factory()->create();
    $foreign = Account::factory()->for(User::factory()->create())->loan()->create(['balance_source' => ImportSource::Manual]);

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openAddModal')
        ->call('openEditModal', $foreign->id)
        ->assertSet('editingAccountId', null)
        ->set('name', 'Car Loan')
        ->set('type', AccountClass::Loan->value)
        ->set('balance', '15000.00')
        ->set('originalPrincipal', '20000.00')
        ->set('startDate', '2024-06-15')
        ->set('rate', '9')
        ->call('save')
        ->assertHasNoErrors();

    $own = Account::query()->where('user_id', $user->id)->sole();

    expect(AccountDetail::query()->where('account_id', $foreign->id)->exists())->toBeFalse()
        ->and(AccountDetail::query()->sole()->account_id)->toBe($own->id);
});

it('ignores a future opening date left over from another account type on a new loan', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openAddModal')
        ->set('type', AccountClass::Transaction->value)
        ->set('openingBalanceDate', '2026-12-01')
        ->set('name', 'Car Loan')
        ->set('type', AccountClass::Loan->value)
        ->set('balance', '15000.00')
        ->set('originalPrincipal', '20000.00')
        ->set('startDate', '2024-06-15')
        ->call('save')
        ->assertHasNoErrors();

    expect(Account::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('records an audit event when loan details are saved', function () {
    $user = User::factory()->create();

    addLoan($user, '15000.00', '20000.00', '2024-06-15');

    $event = AuditEvent::query()->where('action', 'livewire.account-manager.save')->sole();
    expect($event->user_id)->toBe($user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success);
});

it('saves card details without writing valuations', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openAddModal')
        ->set('name', 'Visa')
        ->set('type', AccountClass::CreditCard->value)
        ->set('balance', '-500.00')
        ->set('minimumPayment', '25.00')
        ->set('annualFee', '99.00')
        ->call('save')
        ->assertHasNoErrors();

    $account = Account::query()->where('user_id', $user->id)->sole();
    expect($account->detail->minimum_payment)->toBe(2500)
        ->and($account->detail->annual_fee)->toBe(9900)
        ->and($account->valuations()->count())->toBe(0);
});

it('updates loan details on edit without new valuations', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->loan()->create(['balance_source' => ImportSource::Manual, 'balance' => -1500000]);
    app(AccountValuationService::class)->setOpeningBalance($account, -2000000, CarbonImmutable::parse('2024-06-15'));
    AccountDetail::factory()->create(['account_id' => $account->id, 'rate' => '5.000', 'term_months' => 36, 'original_principal' => 2000000]);

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openEditModal', $account->id)
        ->set('rate', '6.5')
        ->set('termMonths', '48')
        ->call('save')
        ->assertHasNoErrors();

    $detail = $account->detail()->first();
    expect($detail->rate)->toBe('6.500')
        ->and($detail->term_months)->toBe(48)
        ->and($detail->original_principal)->toBe(2000000)
        ->and($account->valuations()->count())->toBe(1);
});

it('saves loan details when editing an untracked loan', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->loan()->untracked()->create(['balance' => -1500000]);

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openEditModal', $account->id)
        ->assertSeeHtml('data-test="account-loan-principal"')
        ->set('originalPrincipal', '20000.00')
        ->set('startDate', '2024-06-15')
        ->set('rate', '6.5')
        ->set('termMonths', '48')
        ->call('save')
        ->assertHasNoErrors();

    $detail = $account->detail()->first();
    expect($detail->original_principal)->toBe(2000000)
        ->and($detail->start_date->toDateString())->toBe('2024-06-15')
        ->and($detail->rate)->toBe('6.500')
        ->and($detail->term_months)->toBe(48);
});

it('saves loan details on a new untracked loan without requiring them', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(AccountManager::class)
        ->call('openAddUntrackedModal')
        ->set('name', 'Investment Mortgage')
        ->set('type', AccountClass::Mortgage->value)
        ->set('balance', '-300000.00')
        ->set('termMonths', '360')
        ->call('save')
        ->assertHasNoErrors();

    $account = Account::query()->where('user_id', $user->id)->sole();
    expect($account->is_tracked)->toBeFalse()
        ->and($account->detail->term_months)->toBe(360)
        ->and($account->detail->original_principal)->toBeNull();
});
