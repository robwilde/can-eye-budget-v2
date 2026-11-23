<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create([
        'description' => 'Rent',
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-04-01',
        'frequency' => RecurrenceFrequency::EveryMonth,
        'merchant_key' => null,
    ]);
});

function duplicateTransaction(object $test, int $amount = 10000, string $description = 'Rent'): Transaction
{
    return Transaction::factory()->for($test->user)->for($test->account)->create([
        'amount' => $amount,
        'description' => $description,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-03-01',
    ]);
}

it('disables making a transaction recurring when a matching plan exists', function () {
    $row = duplicateTransaction($this);

    $component = Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->call('openForEdit', $row->id)
        ->assertSeeHtml('data-testid="plan-already-exists"')
        ->assertSee('already exists');

    expect($component->html())->toMatch('/<button[^>]*\sdisabled[^>]*data-testid="mode-plan"/');

    $component->set('mode', 'plan')->assertSet('mode', 'enter');
});

it('keeps making a transaction recurring open when the amount differs', function () {
    $row = duplicateTransaction($this, 6000);

    $component = Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->call('openForEdit', $row->id)
        ->assertDontSeeHtml('data-testid="plan-already-exists"')
        ->set('mode', 'plan')
        ->assertSet('mode', 'plan')
        ->assertHasNoErrors();

    expect($component->html())->not->toMatch('/<button[^>]*\sdisabled[^>]*data-testid="mode-plan"/');
});

it('refuses to save a plan that already exists', function () {
    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '60 Rent')
        ->set('accountId', $this->account->id)
        ->set('frequency', 'every-month')
        ->set('mode', 'plan')
        ->assertSet('mode', 'plan')
        ->set('descriptionInput', '100 Rent')
        ->call('save')
        ->assertHasErrors(['mode'])
        ->assertSee('already exists');

    expect(PlannedTransaction::query()->count())->toBe(1);
});

it('saves a plan with a new price tier', function () {
    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'expense')
        ->set('descriptionInput', '60 Rent')
        ->set('accountId', $this->account->id)
        ->set('frequency', 'every-month')
        ->set('mode', 'plan')
        ->call('save')
        ->assertHasNoErrors();

    expect(PlannedTransaction::query()->count())->toBe(2);
});

it('does not stop a plan edit from saving', function () {
    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $this->plan->id)
        ->set('notes', 'Changed')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->plan->fresh()->notes)->toBe('Changed');
});

it('does not stop a recurring transfer with the same text', function () {
    $other = Account::factory()->for($this->user)->create();

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '100 Rent')
        ->set('accountId', $this->account->id)
        ->set('transferToAccountId', $other->id)
        ->set('frequency', 'every-month')
        ->set('mode', 'plan')
        ->call('save')
        ->assertHasNoErrors();

    expect(PlannedTransaction::query()->count())->toBe(2);
});

it('checks a bank feed row by its stored values, whatever the form says', function () {
    $row = Transaction::factory()->for($this->user)->for($this->account)->fromRedbark()->create([
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'description' => 'RENT AGENCY',
        'clean_description' => 'Rent',
        'post_date' => '2026-03-01',
    ]);

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)
        ->set('cleanDescription', 'Something else')
        ->set('mode', 'plan')
        ->assertSet('mode', 'enter');

    expect(PlannedTransaction::query()->count())->toBe(1);
});
