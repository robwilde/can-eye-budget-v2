<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountClass;
use App\Enums\SuggestionType;
use App\Livewire\ConfirmPayCycleStep;
use App\Livewire\ConnectBank;
use App\Livewire\GmailConnection;
use App\Livewire\RedbarkAccountSetup;
use App\Models\AnalysisSuggestion;
use App\Models\AuditEvent;
use App\Models\PlannedTransaction;
use App\Models\RedbarkFeed;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use Database\Seeders\CategorySeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** @return list<array<string, mixed>> */
function setupJourneyTransactions(): array
{
    $row = fn (string $id, string $date, string $description, string $amount, ?string $merchant = null): array => [
        'id' => $id, 'accountId' => 'rb_acc_1', 'accountName' => 'Everyday Account', 'status' => 'posted',
        'date' => $date, 'postDate' => $date, 'valueDate' => $date, 'description' => $description,
        'amount' => $amount, 'direction' => str_starts_with($amount, '-') ? 'debit' : 'credit',
        'category' => 'Shopping', 'merchantName' => $merchant, 'merchantCategoryCode' => null,
    ];

    return [
        $row('t1', '2026-09-04', 'SALARY ACME PTY LTD', '2400.00'),
        $row('t2', '2026-09-18', 'SALARY ACME PTY LTD', '2400.00'),
        $row('t3', '2026-10-02', 'SALARY ACME PTY LTD', '2400.00'),
        $row('t4', '2026-10-09', 'WOOLWORTHS 1234 BONDI', '-85.10', 'Woolworths'),
        $row('t5', '2026-09-11', 'WOOLWORTHS 1234 BONDI', '-92.40', 'Woolworths'),
        $row('t6', '2026-09-18', 'WOOLWORTHS 1234 BONDI', '-77.00', 'Woolworths'),
        $row('t7', '2026-09-25', 'WOOLWORTHS 1234 BONDI', '-88.30', 'Woolworths'),
        $row('t8', '2026-10-02', 'WOOLWORTHS 1234 BONDI', '-90.00', 'Woolworths'),
        $row('t9', '2026-09-12', 'NETFLIX.COM', '-22.99', 'Netflix'),
        $row('t10', '2026-10-12', 'NETFLIX.COM', '-22.99', 'Netflix'),
    ];
}

function fakeSetupJourneyRedbark(): void
{
    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        connections: [['id' => 'rb_conn_1', 'category' => 'banking', 'institutionName' => 'Test Bank']],
        transactions: setupJourneyTransactions(),
    );
}

test('a new user goes from sign-up to a categorised, pay-cycle-aware view with every step audited', function () {
    $this->travelTo('2026-10-15 10:00:00');
    config(['queue.default' => 'sync', 'budget.recurring_detection' => true]);
    $this->seed(CategorySeeder::class);
    fakeSetupJourneyRedbark();

    $this->post(route('register.store'), [
        'name' => 'Setup Tester', 'email' => 'setup@example.com',
        'password' => 'password-12345', 'password_confirmation' => 'password-12345',
    ])->assertRedirect(route('connect-bank'));

    $user = User::query()->where('email', 'setup@example.com')->sole();
    $this->actingAs($user)->get(route('connect-bank'))->assertOk();

    $this->travel(40)->seconds();
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(['*/connections*' => Http::response([], 401)]);

    Livewire::actingAs($user)->test(ConnectBank::class)
        ->set('api_key', 'rbk_bad_key_0123456789abcdef')
        ->call('connect')
        ->assertHasErrors('api_key')
        ->assertSet('step', ConnectBank::STEP_CONNECT);

    expect(RedbarkFeed::query()->where('user_id', $user->id)->exists())->toBeFalse();

    $this->travel(20)->seconds();
    fakeSetupJourneyRedbark();

    $onboarding = Livewire::actingAs($user)->test(ConnectBank::class)
        ->set('api_key', 'rbk_test_0123456789abcdef')
        ->call('connect')
        ->assertHasNoErrors()
        ->assertSet('step', ConnectBank::STEP_ACCOUNTS);

    $feed = RedbarkFeed::query()->where('user_id', $user->id)->sole();
    $redbarkAccountId = $feed->accounts()->sole()->id;

    $this->travel(30)->seconds();

    Livewire::actingAs($user)->test(RedbarkAccountSetup::class, ['inOnboarding' => true])
        ->set("choices.{$redbarkAccountId}", 'new:'.AccountClass::Transaction->value)
        ->call('save')
        ->assertDispatched('accounts-set-up');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/transactions')
        && str_contains($request->url(), 'from=2026-09-01'));

    $imported = Transaction::query()->where('user_id', $user->id);

    expect((clone $imported)->count())->toBe(10)
        ->and(UserRule::query()->where('user_id', $user->id)->count())->toBeGreaterThan(0)
        ->and((clone $imported)->whereNotNull('category_id')->count())->toBeGreaterThanOrEqual(6)
        ->and((clone $imported)->whereNotNull('clean_description')->count())->toBeGreaterThanOrEqual(6)
        ->and(AnalysisSuggestion::query()->where('user_id', $user->id)->where('type', SuggestionType::RecurringTransaction)->count()
            + PlannedTransaction::query()->where('user_id', $user->id)->where('is_pay_cycle_income', false)->count())->toBeGreaterThan(0);

    $onboarding->dispatch('accounts-set-up')
        ->assertSet('step', ConnectBank::STEP_PAY_CYCLE);

    $this->travel(45)->seconds();
    $user->refresh();

    Livewire::actingAs($user)->test(ConfirmPayCycleStep::class)
        ->assertSet('payFrequency', 'fortnightly')
        ->assertSet('primaryAccountId', (string) $user->primary_account_id)
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertDispatched('pay-cycle-confirmed');

    $user->refresh();

    expect($user->primary_account_id)->not->toBeNull()
        ->and($user->hasPayCycleConfigured())->toBeTrue();

    $this->travel(15)->seconds();

    $onboarding->dispatch('pay-cycle-confirmed')
        ->assertSet('step', ConnectBank::STEP_GMAIL)
        ->assertSeeLivewire(GmailConnection::class)
        ->call('finish')
        ->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))->assertOk();

    $steps = AuditEvent::query()
        ->where('user_id', $user->id)
        ->whereIn('action', [
            'auth.register',
            'livewire.connect-bank.connect',
            'livewire.redbark-account-setup.save',
            'livewire.connect-bank.advanceToPayCycle',
            'livewire.confirm-pay-cycle-step.confirm',
            'livewire.connect-bank.advanceToGmail',
            'livewire.connect-bank.finish',
        ])
        ->orderBy('id')
        ->get(['action', 'outcome', 'created_at']);

    expect($steps->map(fn (AuditEvent $event) => [$event->action, $event->outcome->value, $event->created_at->toDateTimeString()])->all())->toBe([
        ['auth.register', 'success', '2026-10-15 10:00:00'],
        ['livewire.connect-bank.connect', 'failure', '2026-10-15 10:00:40'],
        ['livewire.connect-bank.connect', 'success', '2026-10-15 10:01:00'],
        ['livewire.redbark-account-setup.save', 'success', '2026-10-15 10:01:30'],
        ['livewire.connect-bank.advanceToPayCycle', 'success', '2026-10-15 10:01:30'],
        ['livewire.confirm-pay-cycle-step.confirm', 'success', '2026-10-15 10:02:15'],
        ['livewire.connect-bank.advanceToGmail', 'success', '2026-10-15 10:02:30'],
        ['livewire.connect-bank.finish', 'success', '2026-10-15 10:02:30'],
    ])
        ->and($steps->first()->created_at->diffInSeconds($steps->last()->created_at))->toBeLessThan(300);
});
