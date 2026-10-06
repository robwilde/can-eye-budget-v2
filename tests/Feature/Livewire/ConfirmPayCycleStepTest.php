<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PayFrequency;
use App\Enums\PipelineRunStatus;
use App\Enums\SuggestionStatus;
use App\Livewire\ConfirmPayCycleStep;
use App\Livewire\ConnectBank;
use App\Livewire\Dashboard;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineRun;
use App\Models\PlannedTransaction;
use App\Models\RedbarkFeed;
use App\Models\RedbarkSyncLog;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/** @return array{0: User, 1: Account, 2: string} */
function payCycleFixture(): array
{
    $user = User::factory()->create();

    return [
        $user,
        Account::factory()->for($user)->create(['name' => 'Everyday']),
        CarbonImmutable::today()->addDays(5)->toDateString(),
    ];
}

function detectedSuggestions(User $user, Account $account, string $nextPay, int $amount = 250000): array
{
    $run = PipelineRun::factory()->for($user)->completed()->create();

    return [
        AnalysisSuggestion::factory()->for($run, 'pipelineRun')->primaryAccount()->create([
            'user_id' => $user->id,
            'payload' => ['account_id' => $account->id],
        ]),
        AnalysisSuggestion::factory()->for($run, 'pipelineRun')->payCycle()->create([
            'user_id' => $user->id,
            'payload' => [
                'pay_amount' => $amount,
                'pay_frequency' => 'fortnightly',
                'next_pay_date' => $nextPay,
                'source_account_id' => $account->id,
                'source_description' => 'SALARY ACME',
                'source_transaction_ids' => [],
            ],
        ]),
    ];
}

test('finishing the account step moves onboarding to step 3 of 3', function () {
    [$user] = payCycleFixture();
    RedbarkFeed::factory()->for($user)->synced()->pendingSetup()->create();

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->assertSee('Step 2 of 3')
        ->dispatch('accounts-set-up')
        ->assertSet('step', ConnectBank::STEP_PAY_CYCLE)
        ->assertSee('Step 3 of 3')
        ->assertSeeLivewire(ConfirmPayCycleStep::class)
        ->assertSeeHtml('data-test="connect-bank-skip"');
});

test('the detected primary account and pay cycle arrive pre-filled', function () {
    [$user, $account, $nextPay] = payCycleFixture();
    detectedSuggestions($user, $account, $nextPay);

    Livewire::actingAs($user)
        ->test(ConfirmPayCycleStep::class)
        ->assertSet('primaryAccountId', (string) $account->id)
        ->assertSet('payAmount', '2500.00')
        ->assertSet('payFrequency', 'fortnightly')
        ->assertSet('nextPayDate', $nextPay)
        ->assertSeeHtml('data-test="pay-cycle-detected"');
});

test('values already auto-applied to the user win over a pending suggestion', function () {
    [$user, $account, $nextPay] = payCycleFixture();
    $suggested = Account::factory()->for($user)->savings()->create();
    detectedSuggestions($user, $suggested, $nextPay);
    $user->update([
        'primary_account_id' => $account->id,
        'pay_amount' => 180000,
        'pay_frequency' => PayFrequency::Weekly,
        'next_pay_date' => $nextPay,
    ]);

    Livewire::actingAs($user)
        ->test(ConfirmPayCycleStep::class)
        ->assertSet('primaryAccountId', (string) $account->id)
        ->assertSet('payAmount', '1800.00')
        ->assertSet('payFrequency', 'weekly');
});

test('confirming the detected values saves them and accepts both suggestions', function () {
    [$user, $account, $nextPay] = payCycleFixture();
    [$primary, $payCycle] = detectedSuggestions($user, $account, $nextPay);

    Livewire::actingAs($user)
        ->test(ConfirmPayCycleStep::class)
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertDispatched('pay-cycle-confirmed');

    $user = $user->fresh();

    expect($user->primary_account_id)->toBe($account->id)
        ->and($user->pay_amount)->toBe(250000)
        ->and($user->pay_frequency)->toBe(PayFrequency::Fortnightly)
        ->and($user->next_pay_date->toDateString())->toBe($nextPay)
        ->and($primary->fresh()->status)->toBe(SuggestionStatus::Accepted)
        ->and($payCycle->fresh()->status)->toBe(SuggestionStatus::Accepted)
        ->and(PlannedTransaction::query()->where('user_id', $user->id)->where('is_pay_cycle_income', true)->sole()->account_id)
        ->toBe($account->id);
});

test('editing a detected value saves the edit and rejects the suggestion it departed from', function () {
    [$user, $account, $nextPay] = payCycleFixture();
    [$primary, $payCycle] = detectedSuggestions($user, $account, $nextPay);

    Livewire::actingAs($user)
        ->test(ConfirmPayCycleStep::class)
        ->set('payFrequency', 'monthly')
        ->call('confirm')
        ->assertHasNoErrors();

    expect($user->fresh()->pay_frequency)->toBe(PayFrequency::Monthly)
        ->and($primary->fresh()->status)->toBe(SuggestionStatus::Accepted)
        ->and($payCycle->fresh()->status)->toBe(SuggestionStatus::Rejected);
});

test('picking a different primary account rejects the pay cycle suggestion and leaves its deposits unlinked', function () {
    [$user, $account, $nextPay] = payCycleFixture();
    [$primary, $payCycle] = detectedSuggestions($user, $account, $nextPay);
    $deposit = Transaction::factory()->for($user)->create(['account_id' => $account->id]);
    $payCycle->update(['payload' => [...$payCycle->payload, 'source_transaction_ids' => [$deposit->id]]]);
    $other = Account::factory()->for($user)->savings()->create();

    Livewire::actingAs($user)
        ->test(ConfirmPayCycleStep::class)
        ->set('primaryAccountId', (string) $other->id)
        ->call('confirm')
        ->assertHasNoErrors();

    expect($user->fresh()->primary_account_id)->toBe($other->id)
        ->and($primary->fresh()->status)->toBe(SuggestionStatus::Rejected)
        ->and($payCycle->fresh()->status)->toBe(SuggestionStatus::Rejected)
        ->and($deposit->fresh()->planned_transaction_id)->toBeNull()
        ->and(PlannedTransaction::query()->where('user_id', $user->id)->where('is_pay_cycle_income', true)->sole()->description)
        ->not->toBe('SALARY ACME');
});

test('with nothing detected the user can enter the account and pay cycle by hand', function () {
    [$user, $account, $nextPay] = payCycleFixture();
    Livewire::actingAs($user)
        ->test(ConfirmPayCycleStep::class)
        ->assertSet('primaryAccountId', '')
        ->assertSeeHtml('data-test="pay-cycle-manual"')
        ->set('primaryAccountId', (string) $account->id)
        ->set('payAmount', '1234.56')
        ->set('payFrequency', 'weekly')
        ->set('nextPayDate', $nextPay)
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertDispatched('pay-cycle-confirmed');

    $user = $user->fresh();

    expect($user->primary_account_id)->toBe($account->id)
        ->and($user->pay_amount)->toBe(123456)
        ->and($user->pay_frequency)->toBe(PayFrequency::Weekly)
        ->and($user->next_pay_date->toDateString())->toBe($nextPay);
});

test('an incomplete or invalid form saves nothing', function (string $field, string $value) {
    [$user, $account, $nextPay] = payCycleFixture();
    $component = Livewire::actingAs($user)
        ->test(ConfirmPayCycleStep::class)
        ->set('primaryAccountId', (string) $account->id)
        ->set('payAmount', '1000')
        ->set('payFrequency', 'weekly')
        ->set('nextPayDate', $nextPay)
        ->set($field, $value)
        ->call('confirm');

    $component->assertHasErrors($field)->assertNotDispatched('pay-cycle-confirmed');

    expect($user->fresh()->hasPayCycleConfigured())->toBeFalse()
        ->and($user->fresh()->primary_account_id)->toBeNull();
})->with([
    'no account' => ['primaryAccountId', ''],
    'zero amount' => ['payAmount', '0'],
    'unknown frequency' => ['payFrequency', 'daily'],
    'past pay date' => ['nextPayDate', '2020-01-01'],
]);

test('an account that is not the user\'s own cannot be made primary', function () {
    [$user, , $nextPay] = payCycleFixture();
    $stranger = Account::factory()->for(User::factory())->create();

    Livewire::actingAs($user)
        ->test(ConfirmPayCycleStep::class)
        ->set('primaryAccountId', (string) $stranger->id)
        ->set('payAmount', '1000')
        ->set('payFrequency', 'weekly')
        ->set('nextPayDate', $nextPay)
        ->call('confirm')
        ->assertHasErrors('primaryAccountId');

    expect($user->fresh()->primary_account_id)->toBeNull();
});

test('other users\' pending suggestions are left alone', function () {
    [$user, $account, $nextPay] = payCycleFixture();
    $other = User::factory()->create();
    $otherAccount = Account::factory()->for($other)->create();
    [$theirPrimary] = detectedSuggestions($other, $otherAccount, $nextPay);

    Livewire::actingAs($user)
        ->test(ConfirmPayCycleStep::class)
        ->set('primaryAccountId', (string) $account->id)
        ->set('payAmount', '1000')
        ->set('payFrequency', 'weekly')
        ->set('nextPayDate', $nextPay)
        ->call('confirm')
        ->assertHasNoErrors();

    expect($theirPrimary->fresh()->status)->toBe(SuggestionStatus::Pending);
});

test('the form is held back while the first sync is still running', function () {
    [$user] = payCycleFixture();
    $feed = RedbarkFeed::factory()->for($user)->create();
    RedbarkSyncLog::factory()->for($feed, 'feed')->create();

    Livewire::actingAs($user)
        ->test(ConfirmPayCycleStep::class)
        ->assertSeeHtml('data-test="pay-cycle-analysing"')
        ->assertDontSeeHtml('data-test="pay-cycle-confirm"')
        ->assertSeeHtml('wire:poll.3s');
});

test('the form is held back after the sync finishes until the analysis has run', function () {
    [$user] = payCycleFixture();
    $feed = RedbarkFeed::factory()->for($user)->create();
    RedbarkSyncLog::factory()->for($feed, 'feed')->completed()->create();

    Livewire::actingAs($user)
        ->test(ConfirmPayCycleStep::class)
        ->assertSeeHtml('data-test="pay-cycle-analysing"')
        ->assertDontSeeHtml('data-test="pay-cycle-confirm"');
});

test('the form opens once the analysis that followed the sync has completed', function () {
    [$user] = payCycleFixture();
    $feed = RedbarkFeed::factory()->for($user)->create();
    RedbarkSyncLog::factory()->for($feed, 'feed')->completed()->create();
    PipelineRun::factory()->for($user)->completed()->create();

    Livewire::actingAs($user)
        ->test(ConfirmPayCycleStep::class)
        ->assertDontSeeHtml('data-test="pay-cycle-analysing"')
        ->assertSeeHtml('data-test="pay-cycle-confirm"')
        ->assertDontSeeHtml('wire:poll');
});

test('an analysis that started before the sync finished does not open the form', function () {
    [$user] = payCycleFixture();
    $feed = RedbarkFeed::factory()->for($user)->create();
    RedbarkSyncLog::factory()->for($feed, 'feed')->completed()->create([
        'created_at' => now()->subMinutes(5),
        'updated_at' => now(),
    ]);
    PipelineRun::factory()->for($user)->completed()->create(['created_at' => now()->subMinutes(2)]);

    Livewire::actingAs($user)
        ->test(ConfirmPayCycleStep::class)
        ->assertSeeHtml('data-test="pay-cycle-analysing"');
});

test('a failed sync still waits for the analysis it queued', function () {
    [$user] = payCycleFixture();
    $feed = RedbarkFeed::factory()->for($user)->create();
    RedbarkSyncLog::factory()->for($feed, 'feed')->failed()->create();

    Livewire::actingAs($user)
        ->test(ConfirmPayCycleStep::class)
        ->assertSeeHtml('data-test="pay-cycle-analysing"')
        ->assertDontSeeHtml('data-test="pay-cycle-confirm"');
});

test('a failed sync opens the form once the analysis has run', function () {
    [$user] = payCycleFixture();
    $feed = RedbarkFeed::factory()->for($user)->create();
    RedbarkSyncLog::factory()->for($feed, 'feed')->failed()->create();
    PipelineRun::factory()->for($user)->completed()->create();

    Livewire::actingAs($user)
        ->test(ConfirmPayCycleStep::class)
        ->assertSeeHtml('data-test="pay-cycle-confirm"')
        ->assertSeeHtml('data-test="pay-cycle-manual"');
});

test('a failed sync that queued no analysis opens the form for manual entry after a grace period', function () {
    [$user] = payCycleFixture();
    $feed = RedbarkFeed::factory()->for($user)->create();
    RedbarkSyncLog::factory()->for($feed, 'feed')->failed()->create(['updated_at' => now()->subMinutes(10)]);

    Livewire::actingAs($user)
        ->test(ConfirmPayCycleStep::class)
        ->assertSeeHtml('data-test="pay-cycle-confirm"');
});

test('confirming is refused server-side while the analysis is still running', function () {
    [$user, $account, $nextPay] = payCycleFixture();
    $feed = RedbarkFeed::factory()->for($user)->create();
    RedbarkSyncLog::factory()->for($feed, 'feed')->create();

    Livewire::actingAs($user)
        ->test(ConfirmPayCycleStep::class)
        ->set('primaryAccountId', (string) $account->id)
        ->set('payAmount', '1000')
        ->set('payFrequency', 'weekly')
        ->set('nextPayDate', $nextPay)
        ->call('confirm')
        ->assertHasErrors('primaryAccountId')
        ->assertNotDispatched('pay-cycle-confirmed');

    expect($user->fresh()->primary_account_id)->toBeNull()
        ->and($user->fresh()->hasPayCycleConfigured())->toBeFalse();
});

test('refreshing onboarding with unresolved pay cycle suggestions returns to step 3', function () {
    [$user, $account, $nextPay] = payCycleFixture();
    RedbarkFeed::factory()->for($user)->synced()->create(['pending_account_setup' => false]);
    detectedSuggestions($user, $account, $nextPay);

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->assertNoRedirect()
        ->assertSet('step', ConnectBank::STEP_PAY_CYCLE);
});

test('a sync stranded past its window opens the form for manual entry', function () {
    [$user] = payCycleFixture();
    $feed = RedbarkFeed::factory()->for($user)->create();
    RedbarkSyncLog::factory()->for($feed, 'feed')->create(['created_at' => now()->subHour()]);

    Livewire::actingAs($user)
        ->test(ConfirmPayCycleStep::class)
        ->assertSeeHtml('data-test="pay-cycle-confirm"');
});

test('skipping leaves the pay cycle unset so the dashboard keeps prompting for it', function () {
    [$user, $account, $nextPay] = payCycleFixture();
    detectedSuggestions($user, $account, $nextPay);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSee('SET UP PAY CYCLE');

    expect(AnalysisSuggestion::query()->where('user_id', $user->id)->pending()->count())->toBe(2)
        ->and($user->fresh()->hasPayCycleConfigured())->toBeFalse();
});

test('refreshing onboarding while the analysis runs returns to step 3', function () {
    [$user] = payCycleFixture();
    $feed = RedbarkFeed::factory()->for($user)->synced()->create(['pending_account_setup' => false]);
    RedbarkSyncLog::factory()->for($feed, 'feed')->completed()->create();

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->assertNoRedirect()
        ->assertSet('step', ConnectBank::STEP_PAY_CYCLE);
});

test('refreshing onboarding when nothing was detected returns to step 3 for manual entry', function () {
    [$user] = payCycleFixture();
    RedbarkFeed::factory()->for($user)->synced()->create(['pending_account_setup' => false]);

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->assertNoRedirect()
        ->assertSet('step', ConnectBank::STEP_PAY_CYCLE);
});

test('a completed sync whose analysis never ran opens the form once the wait window passes', function () {
    [$user] = payCycleFixture();
    $feed = RedbarkFeed::factory()->for($user)->create();
    RedbarkSyncLog::factory()->for($feed, 'feed')->completed()->create([
        'created_at' => now()->subHour(),
        'updated_at' => now()->subMinutes(30),
    ]);

    Livewire::actingAs($user)
        ->test(ConfirmPayCycleStep::class)
        ->assertSeeHtml('data-test="pay-cycle-confirm"')
        ->assertDontSeeHtml('wire:poll');
});

test('an analysis stuck running past the wait window does not hold the form closed', function () {
    [$user] = payCycleFixture();
    $feed = RedbarkFeed::factory()->for($user)->create();
    RedbarkSyncLog::factory()->for($feed, 'feed')->completed()->create([
        'created_at' => now()->subHour(),
        'updated_at' => now()->subMinutes(30),
    ]);
    PipelineRun::factory()->for($user)->create(['status' => PipelineRunStatus::Running, 'created_at' => now()->subMinutes(29)]);

    Livewire::actingAs($user)
        ->test(ConfirmPayCycleStep::class)
        ->assertSeeHtml('data-test="pay-cycle-confirm"');
});

test('the failed-sync grace is not re-armed by the stuck-sync sweep touching updated_at', function () {
    [$user] = payCycleFixture();
    $feed = RedbarkFeed::factory()->for($user)->create();
    RedbarkSyncLog::factory()->for($feed, 'feed')->failed()->create([
        'created_at' => now()->subMinutes(20),
        'updated_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(ConfirmPayCycleStep::class)
        ->assertSeeHtml('data-test="pay-cycle-confirm"');
});

test('confirming an auto-applied pay cycle unchanged keeps the detected income description', function () {
    [$user, $account, $nextPay] = payCycleFixture();
    $user->update([
        'primary_account_id' => $account->id,
        'pay_amount' => 250000,
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => $nextPay,
    ]);
    PlannedTransaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'is_pay_cycle_income' => true,
        'description' => 'SALARY ACME',
    ]);

    Livewire::actingAs($user)
        ->test(ConfirmPayCycleStep::class)
        ->call('confirm')
        ->assertHasNoErrors();

    expect(PlannedTransaction::query()->where('user_id', $user->id)->where('is_pay_cycle_income', true)->sole()->description)
        ->toBe('SALARY ACME');
});
