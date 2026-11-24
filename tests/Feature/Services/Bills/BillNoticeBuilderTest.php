<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\BillNotice;
use App\Enums\PlannedTransactionStatus;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Livewire\Bills;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\PlannedTransactionPriceChange;
use App\Models\User;
use App\Services\Bills\BillNoticeBuilder;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    useAppTimezone('UTC');
    config(['budget.bills_page' => true, 'budget.material_price_change_bps' => 1000]);
    $this->travelTo(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->user->update(['primary_account_id' => $this->account->id]);
    $this->builder = app(BillNoticeBuilder::class);
});

function noticePlan(object $test, string $name, array $attributes = [], ?User $user = null): PlannedTransaction
{
    $user ??= $test->user;
    $account = $user->is($test->user) ? $test->account : Account::factory()->for($user)->create();

    return PlannedTransaction::factory()->for($user)->for($account)->create([
        'description' => $name, 'amount' => 5000, 'direction' => TransactionDirection::Debit, 'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2030-01-01', 'until_date' => null, 'merchant_key' => null, ...$attributes,
    ]);
}

it('lists an imminent trial first as urgent', function () {
    noticePlan($this, 'Renewing', ['renewal_on' => '2026-03-25']);
    noticePlan($this, 'Trialing', ['trial_ends_on' => '2026-03-17']);

    $notices = $this->builder->build($this->user);

    expect($notices)->toHaveCount(2)->and($notices[0]->name)->toBe('Trialing')->and($notices[0]->urgent)->toBeTrue()->and($notices[0]->kind)->toBe(BillNotice::TRIAL)
        ->and($notices[1]->kind)->toBe(BillNotice::RENEWAL)->and($notices[1]->urgent)->toBeFalse();

    Livewire::actingAs($this->user)->test(Bills::class)->assertSeeHtml('data-testid="bill-notices"')->assertSeeHtml('data-urgent="true"')->assertSee('Trialing');
});

it('ignores trials ending beyond fourteen days', function () {
    noticePlan($this, 'Far trial', ['trial_ends_on' => '2026-04-04']);
    noticePlan($this, 'Edge trial', ['trial_ends_on' => '2026-03-29']);

    expect(collect($this->builder->build($this->user))->pluck('name')->all())->toBe(['Edge trial']);

    Livewire::actingAs($this->user)->test(Bills::class)->assertDontSee('Far trial');
});

it('flags a large recent price change as material', function () {
    $plan = noticePlan($this, 'Phone');
    PlannedTransactionPriceChange::factory()->create(['planned_transaction_id' => $plan->id, 'previous_amount' => 5000, 'new_amount' => 8000, 'effective_on' => '2026-03-08']);

    $notice = $this->builder->build($this->user)[0];

    expect($notice->kind)->toBe(BillNotice::PRICE_CHANGE)->and($notice->material)->toBeTrue()->and($notice->urgent)->toBeTrue()->and($notice->previousAmount)->toBe(5000)->and($notice->newAmount)->toBe(8000);

    Livewire::actingAs($this->user)->test(Bills::class)->assertSeeHtml('data-testid="notice-material"')->assertSee('$50.00 to $80.00');
});

it('shows a small price change without urgency and ignores old ones', function () {
    $plan = noticePlan($this, 'Phone');
    PlannedTransactionPriceChange::factory()->create(['planned_transaction_id' => $plan->id, 'previous_amount' => 5000, 'new_amount' => 5100, 'effective_on' => '2026-03-08']);
    PlannedTransactionPriceChange::factory()->create(['planned_transaction_id' => $plan->id, 'previous_amount' => 4000, 'new_amount' => 9000, 'effective_on' => '2026-01-01', 'created_at' => '2026-01-02']);

    $notices = $this->builder->build($this->user);

    expect($notices)->toHaveCount(1)->and($notices[0]->material)->toBeFalse()->and($notices[0]->urgent)->toBeFalse();
});

it('uses the configured material threshold', function () {
    config(['budget.material_price_change_bps' => 100]);
    $plan = noticePlan($this, 'Phone');
    PlannedTransactionPriceChange::factory()->create(['planned_transaction_id' => $plan->id, 'previous_amount' => 5000, 'new_amount' => 5100, 'effective_on' => '2026-03-08']);

    expect($this->builder->build($this->user)[0]->material)->toBeTrue();
});

it('leaves inactive plans out of notices', function () {
    $plan = noticePlan($this, 'Paused', ['status' => PlannedTransactionStatus::Inactive, 'renewal_on' => '2026-03-20', 'trial_ends_on' => '2026-03-16']);
    PlannedTransactionPriceChange::factory()->create(['planned_transaction_id' => $plan->id, 'previous_amount' => 5000, 'new_amount' => 8000, 'effective_on' => '2026-03-08']);
    $savings = Account::factory()->for($this->user)->create();
    noticePlan($this, 'Sweep', ['transfer_to_account_id' => $savings->id, 'renewal_on' => '2026-03-20']);
    noticePlan($this, 'Salary', ['direction' => TransactionDirection::Credit, 'renewal_on' => '2026-03-20']);

    expect($this->builder->build($this->user))->toBe([]);

    Livewire::actingAs($this->user)->test(Bills::class)->assertDontSeeHtml('data-testid="bill-notices"');
});

it('never shows another users notices', function () {
    $other = User::factory()->create();
    $theirs = noticePlan($this, 'Theirs', ['trial_ends_on' => '2026-03-16'], $other);
    PlannedTransactionPriceChange::factory()->create(['planned_transaction_id' => $theirs->id, 'previous_amount' => 5000, 'new_amount' => 8000, 'effective_on' => '2026-03-08']);

    expect($this->builder->build($this->user))->toBe([]);

    Livewire::actingAs($this->user)->test(Bills::class)->assertDontSee('Theirs');
});

it('orders the rest by nearness to today', function () {
    noticePlan($this, 'Later', ['renewal_on' => '2026-03-28']);
    noticePlan($this, 'Sooner', ['renewal_on' => '2026-03-18']);

    expect(collect($this->builder->build($this->user))->pluck('name')->all())->toBe(['Sooner', 'Later']);
});

it('shows a price change recorded this week whose effective date is older', function () {
    $plan = noticePlan($this, 'Phone');
    PlannedTransactionPriceChange::factory()->create(['planned_transaction_id' => $plan->id, 'previous_amount' => 5000, 'new_amount' => 8000, 'effective_on' => '2026-02-10', 'created_at' => '2026-03-14']);

    expect($this->builder->build($this->user))->toHaveCount(1);
});

it('puts an urgent notice ahead of a nearer one that is not urgent', function () {
    noticePlan($this, 'Renewal', ['renewal_on' => '2026-03-16']);
    noticePlan($this, 'Trial', ['trial_ends_on' => '2026-03-18']);

    expect(collect($this->builder->build($this->user))->pluck('name')->all())->toBe(['Trial', 'Renewal']);
});
