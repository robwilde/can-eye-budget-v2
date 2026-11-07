<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Actions\Onboarding\ConfirmPayCycle;
use App\Enums\PayFrequency;
use App\Enums\SuggestionStatus;
use App\Livewire\AccountManager;
use App\Livewire\AnalysisSuggestions;
use App\Livewire\ConfirmPayCycleStep;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineRun;
use App\Models\User;
use App\Services\SuggestionApplier;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->current = Account::factory()->for($this->user)->create();
    $this->user->update(['primary_account_id' => $this->current->id]);
});

dataset('ineligible primary accounts', [
    'loan account' => [fn (User $user) => Account::factory()->for($user)->loan()->create()],
    'mortgage account' => [fn (User $user) => Account::factory()->for($user)->mortgage()->create()],
    'disabled account' => [fn (User $user) => Account::factory()->for($user)->inactive()->create()],
]);

function primarySuggestionFor(User $user, Account $account): AnalysisSuggestion
{
    return AnalysisSuggestion::factory()
        ->for(PipelineRun::factory()->for($user)->completed()->create(), 'pipelineRun')
        ->primaryAccount()
        ->create(['user_id' => $user->id, 'payload' => [
            'account_id' => $account->id,
            'account_name' => $account->name,
            'income_amount' => 250000,
            'income_frequency' => 'fortnightly',
            'income_description' => 'SALARY',
            'confidence_score' => 0.9,
            'matched_transaction_ids' => [],
            'outbound_transfer_count' => 0,
        ]]);
}

function confirmPayCycleFor(User $user, Account $account): Testable
{
    return Livewire::actingAs($user)
        ->test(ConfirmPayCycleStep::class)
        ->set('primaryAccountId', (string) $account->id)
        ->set('payAmount', '2500.00')
        ->set('payFrequency', PayFrequency::Fortnightly->value)
        ->set('nextPayDate', CarbonImmutable::today()->addDays(5)->toDateString())
        ->call('confirm');
}

it('rejects an ineligible primary account', function (Closure $makeAccount) {
    $account = $makeAccount($this->user);

    confirmPayCycleFor($this->user, $account)
        ->assertHasErrors('primaryAccountId')
        ->assertNotDispatched('pay-cycle-confirmed');

    expect($this->user->fresh()->primary_account_id)->toBe($this->current->id);
})->with('ineligible primary accounts');

it('accepts a savings or credit card account as primary', function (string $state) {
    $account = Account::factory()->for($this->user)->{$state}()->create();

    $accepted = app(SuggestionApplier::class)->applyPrimaryAccount(primarySuggestionFor($this->user, $account), $this->user);

    expect($accepted)->toBeTrue()
        ->and($this->user->fresh()->primary_account_id)->toBe($account->id);
})->with(['savings', 'creditCard']);

it('lets a credit card be confirmed as primary in onboarding', function () {
    $card = Account::factory()->for($this->user)->creditCard()->create();

    $step = confirmPayCycleFor($this->user, $card)
        ->assertHasNoErrors()
        ->assertDispatched('pay-cycle-confirmed');

    expect($step->instance()->accounts()->pluck('id')->all())->toContain($card->id)
        ->and($this->user->fresh()->primary_account_id)->toBe($card->id);
});

it('cannot flag or pick another users account', function () {
    $other = User::factory()->create();
    $foreign = Account::factory()->for($other)->create();

    confirmPayCycleFor($this->user, $foreign)->assertHasErrors('primaryAccountId');

    $accepted = app(SuggestionApplier::class)->applyPrimaryAccount(primarySuggestionFor($this->user, $foreign), $this->user);

    expect($accepted)->toBeFalse()
        ->and($this->user->fresh()->primary_account_id)->toBe($this->current->id);

    Livewire::actingAs($this->user)
        ->test(AccountManager::class)
        ->call('openEditModal', $foreign->id)
        ->assertSet('showFormModal', false)
        ->assertSet('editingAccountId', null);

    expect($foreign->fresh()->exclude_from_reports)->toBeFalse();
});

it('does not apply an ineligible suggested primary account', function (Closure $makeAccount) {
    $suggestion = primarySuggestionFor($this->user, $makeAccount($this->user));

    $applied = app(SuggestionApplier::class)->applyPrimaryAccount($suggestion, $this->user);

    expect($applied)->toBeFalse()
        ->and($this->user->fresh()->primary_account_id)->toBe($this->current->id)
        ->and($suggestion->fresh()->status)->toBe(SuggestionStatus::Pending);
})->with('ineligible primary accounts');

it('refuses to accept an ineligible primary account suggestion', function () {
    $suggestion = primarySuggestionFor($this->user, Account::factory()->for($this->user)->loan()->create());

    Livewire::actingAs($this->user)
        ->test(AnalysisSuggestions::class)
        ->call('acceptPrimaryAccount', $suggestion->id);

    expect($this->user->fresh()->primary_account_id)->toBe($this->current->id)
        ->and($suggestion->fresh()->status)->toBe(SuggestionStatus::Pending);
});

it('refuses to confirm an ineligible account through the onboarding action', function () {
    $loan = Account::factory()->for($this->user)->loan()->create();

    expect(fn () => app(ConfirmPayCycle::class)->handle($this->user, $loan, 250000, PayFrequency::Weekly, CarbonImmutable::today()->addDays(3)->toDateString()))
        ->toThrow(InvalidArgumentException::class);

    expect($this->user->fresh()->primary_account_id)->toBe($this->current->id);
});
