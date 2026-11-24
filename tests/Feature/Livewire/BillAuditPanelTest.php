<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RecurrenceFrequency;
use App\Livewire\BillAuditPanel;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

it('shows the audit sections on the rules page', function () {
    PlannedTransaction::factory()->for($this->user)->for($this->account)->create(['description' => 'Gym', 'amount' => 5000, 'frequency' => RecurrenceFrequency::EveryMonth, 'merchant_key' => null, 'start_date' => '2026-01-10']);
    PlannedTransaction::factory()->for($this->user)->for($this->account)->create(['description' => 'GYM', 'amount' => 5000, 'frequency' => RecurrenceFrequency::EveryMonth, 'merchant_key' => null, 'start_date' => '2026-01-10']);

    $this->actingAs($this->user)->get(route('rules'))->assertOk()->assertSeeHtml('data-testid="bill-audit"')->assertSee('Possible duplicates')->assertSee('Same name, amount and day as another bill');
});

it('refuses a look back outside the range with a message and no report', function () {
    Livewire::actingAs($this->user)->test(BillAuditPanel::class)->set('lookback', '30')->assertSeeHtml('data-testid="audit-lookback-error"')->assertDontSeeHtml('data-testid="audit-duplicates"')
        ->set('lookback', 'abc')->assertSeeHtml('data-testid="audit-lookback-error"')
        ->set('lookback', '6')->assertDontSeeHtml('data-testid="audit-lookback-error"')->assertSeeHtml('data-testid="audit-duplicates"');
});

it('says the audit is off while recurring is off', function () {
    $this->user->updatePreferences(['recurringEnabled' => false]);

    Livewire::actingAs($this->user->fresh())->test(BillAuditPanel::class)->assertSeeHtml('data-testid="audit-disabled"');
});
