<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PlanRefusal;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Exceptions\PlanWriteRefusedException;
use App\Livewire\PlannedTransactionManager;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\Recurring\PlanWriter;
use Livewire\Livewire;
use Tests\Concerns\CallsApi;

uses(CallsApi::class);

beforeEach(function (): void {
    $this->travelTo('2026-10-15');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->token = $this->apiTokenFor($this->user);
});

function parityPlan(object $test, array $overrides = []): PlannedTransaction
{
    return PlannedTransaction::factory()->for($test->user)->for($test->account)->create($overrides + [
        'description' => 'Netflix',
        'amount' => 1599,
        'direction' => TransactionDirection::Debit,
        'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2026-11-01',
        'merchant_key' => null,
        'category_id' => null,
    ]);
}

it('refuses a duplicate series on update from the plan manager, the modal and the API', function () {
    parityPlan($this);
    $manager = parityPlan($this, ['amount' => 1000]);
    $modal = parityPlan($this, ['amount' => 1100]);
    $api = parityPlan($this, ['amount' => 1200]);

    Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)
        ->call('openEdit', $manager->id)->set('amount', '15.99')->call('save')
        ->assertHasErrors('amount');

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $modal->id)->set('descriptionInput', '15.99 Netflix')->call('save')
        ->assertHasErrors('mode')
        ->assertSee('A plan like this already exists.');

    $this->apiCall('PATCH', 'planned-transactions/'.$api->id, $this->token, ['amount' => 1599])->assertStatus(409);

    expect($manager->fresh()->amount)->toBe(1000)
        ->and($modal->fresh()->amount)->toBe(1100)
        ->and($api->fresh()->amount)->toBe(1200);
});

it('does not count a plan as its own duplicate and ignores legacy duplicates when no key field changes', function () {
    parityPlan($this);
    $twin = parityPlan($this);

    Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)
        ->call('openEdit', $twin->id)->set('notes', 'changed')->call('save')
        ->assertHasNoErrors();

    $this->apiCall('PATCH', 'planned-transactions/'.$twin->id, $this->token, ['notes' => 'again'])->assertOk();

    expect($twin->fresh()->notes)->toBe('again');
});

it('keeps a hidden category on a plan edited from the plan manager', function () {
    $category = Category::factory()->for($this->user)->create();
    $plan = parityPlan($this, ['category_id' => $category->id]);
    $category->update(['is_hidden' => true]);

    Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)
        ->call('openEdit', $plan->id)->set('amount', '12.00')->call('save')
        ->assertHasNoErrors();

    expect($plan->fresh()->category_id)->toBe($category->id)
        ->and($plan->fresh()->amount)->toBe(1200);
});

it('keeps a hidden category on a plan edited from the modal and through the API', function () {
    $category = Category::factory()->for($this->user)->create();
    $modal = parityPlan($this, ['category_id' => $category->id, 'description' => 'Gym', 'amount' => 3000]);
    $api = parityPlan($this, ['category_id' => $category->id, 'description' => 'Pool', 'amount' => 4000]);
    $category->update(['is_hidden' => true]);

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $modal->id)->set('notes', 'edited')->call('save')
        ->assertHasNoErrors();

    $this->apiCall('PATCH', 'planned-transactions/'.$api->id, $this->token, ['category_id' => $category->id, 'notes' => 'edited'])->assertOk();

    expect($modal->fresh()->category_id)->toBe($category->id)
        ->and($api->fresh()->category_id)->toBe($category->id);
});

it('refuses a new plan starting today or earlier from the API', function (string $start, bool $accepted) {
    $payload = [
        'account_id' => $this->account->id,
        'amount' => 1234,
        'direction' => 'debit',
        'description' => 'Gym',
        'start_date' => $start,
        'frequency' => 'every-month',
    ];

    $response = $this->apiCall('POST', 'planned-transactions', $this->token, $payload);

    if ($accepted) {
        $response->assertCreated();
    } else {
        $response->assertUnprocessable()->assertJsonValidationErrors('start_date');
    }

    expect(PlannedTransaction::query()->count())->toBe($accepted ? 1 : 0);
})->with([
    'yesterday' => ['2026-10-14', false],
    'today' => ['2026-10-15', false],
    'tomorrow' => ['2026-10-16', true],
]);

it('still lets an existing plan move its start date to the past through the API', function () {
    $plan = parityPlan($this);

    $this->apiCall('PATCH', 'planned-transactions/'.$plan->id, $this->token, ['start_date' => '2026-09-01'])->assertOk();

    expect($plan->fresh()->start_date->toDateString())->toBe('2026-09-01');
});

it('refuses plan writes from the web while recurring is switched off', function () {
    $plan = parityPlan($this);
    $this->user->updatePreferences(['recurringEnabled' => false]);

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->call('openForAdd', '2026-12-10')
        ->assertSet('mode', 'enter')
        ->set('mode', 'plan')
        ->set('transactionType', 'expense')
        ->set('accountId', $this->account->id)
        ->set('frequency', 'every-month')
        ->set('descriptionInput', '10.00 Plan')
        ->call('save')
        ->assertHasErrors('mode')
        ->assertSee('Recurring is switched off');

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $plan->id)
        ->assertSet('showModal', false)
        ->assertSet('editingPlannedTransactionId', null);

    $manager = Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)
        ->call('openEdit', $plan->id)
        ->assertSet('showEditModal', false)
        ->set('editingId', $plan->id)->set('amount', '99.00')->set('frequency', 'every-month')->set('direction', 'debit')
        ->call('save');

    $manager->call('toggleActive', $plan->id)
        ->set('deletingId', $plan->id)->call('delete');

    expect(PlannedTransaction::query()->count())->toBe(1)
        ->and($plan->fresh()->amount)->toBe(1599)
        ->and($plan->fresh()->isActive())->toBeTrue();
});

it('does not let a hidden category through when a plan occurrence is recorded as a transaction', function () {
    $category = Category::factory()->for($this->user)->create();
    $plan = parityPlan($this, ['category_id' => $category->id]);
    $category->update(['is_hidden' => true]);

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $plan->id, occurrenceDate: '2026-10-10')
        ->call('save')
        ->assertHasErrors('categoryId');

    expect(App\Models\Transaction::query()->count())->toBe(0);
});

it('shows a notice and hides the plan toggle on the web while recurring is switched off', function () {
    $this->user->updatePreferences(['recurringEnabled' => false]);

    Livewire::actingAs($this->user)->test(TransactionModal::class)
        ->call('openForAdd', '2026-12-10')
        ->assertDontSeeHtml('data-testid="mode-plan"');

    Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)
        ->assertSeeHtml('data-testid="recurring-off-notice"');
});

it('refuses pay cycle income plan edits through the shared writer', function () {
    $plan = parityPlan($this, ['is_pay_cycle_income' => true, 'direction' => TransactionDirection::Credit]);
    $writer = app(PlanWriter::class);

    expect($writer->refusalFor($this->user, $plan))->toBe(PlanRefusal::PayCycleIncome)
        ->and(fn () => $writer->update($this->user, $plan, ['amount' => 5]))->toThrow(PlanWriteRefusedException::class)
        ->and(fn () => $writer->discard($this->user, $plan))->toThrow(PlanWriteRefusedException::class)
        ->and($plan->fresh()->amount)->toBe(1599);

    $this->apiCall('PATCH', 'planned-transactions/'.$plan->id, $this->token, ['amount' => 5])->assertForbidden();
});

it('applies the writer rules to a plan created from any caller', function () {
    $writer = app(PlanWriter::class);
    $untracked = Account::factory()->for($this->user)->create(['is_tracked' => false]);
    $hidden = Category::factory()->for($this->user)->create(['is_hidden' => true]);
    $base = ['account_id' => $this->account->id, 'amount' => 700, 'direction' => TransactionDirection::Debit, 'description' => 'Pool', 'start_date' => '2026-11-01', 'frequency' => RecurrenceFrequency::EveryMonth];

    $reason = static function (array $attributes) use ($writer): ?PlanRefusal {
        try {
            $writer->create(test()->user, $attributes);
        } catch (PlanWriteRefusedException $e) {
            return $e->reason;
        }

        return null;
    };

    expect($reason([...$base, 'account_id' => $untracked->id]))->toBe(PlanRefusal::UntrackedAccount)
        ->and($reason([...$base, 'category_id' => $hidden->id]))->toBe(PlanRefusal::HiddenCategory)
        ->and($reason([...$base, 'start_date' => '2026-10-15']))->toBe(PlanRefusal::StartNotAfterToday)
        ->and($reason($base))->toBeNull()
        ->and($reason($base))->toBe(PlanRefusal::Duplicate)
        ->and(PlannedTransaction::query()->count())->toBe(1);
});

it('does not refuse an unrelated edit of a plan that carries a merged merchants old key', function () {
    $merchant = App\Models\Merchant::factory()->for($this->user)->create(['name' => 'Netflix']);
    App\Models\MerchantAlias::factory()->create(['user_id' => $this->user->id, 'merchant_id' => $merchant->id, 'merchant_key' => 'old-netflix-key']);
    parityPlan($this, ['merchant_key' => $merchant->merchant_key, 'description' => 'Netflix standard']);
    $plan = parityPlan($this, ['merchant_key' => 'old-netflix-key', 'description' => 'NETFLIX.COM']);

    Livewire::actingAs($this->user)->test(PlannedTransactionManager::class)
        ->call('openEdit', $plan->id)->set('merchantId', $merchant->id)->set('notes', 'changed')->call('save')
        ->assertHasNoErrors();

    expect($plan->fresh()->notes)->toBe('changed');
});

it('refuses an API amount change that makes a plan match another by merchant', function () {
    $merchant = App\Models\Merchant::factory()->for($this->user)->create(['name' => 'Netflix']);
    parityPlan($this, ['merchant_key' => $merchant->merchant_key, 'description' => 'Netflix standard', 'amount' => 1599]);
    $plan = parityPlan($this, ['merchant_key' => $merchant->merchant_key, 'description' => 'NETFLIX.COM', 'amount' => 2000]);

    $this->apiCall('PATCH', 'planned-transactions/'.$plan->id, $this->token, ['amount' => 1599])->assertStatus(409);

    expect($plan->fresh()->amount)->toBe(2000);
});

it('refuses turning a transfer plan into a plan that duplicates another', function () {
    parityPlan($this, ['description' => 'Rent', 'amount' => 5000]);
    $transfer = PlannedTransaction::factory()->for($this->user)->for($this->account)->transfer()->create([
        'description' => 'Rent',
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2026-11-01',
    ]);

    $writer = app(PlanWriter::class);

    expect(fn () => $writer->update($this->user, $transfer, ['transfer_to_account_id' => null]))->toThrow(PlanWriteRefusedException::class)
        ->and($transfer->fresh()->transfer_to_account_id)->not->toBeNull();
});
