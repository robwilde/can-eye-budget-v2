<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ApiKeyScope;
use App\Enums\PlannedTransactionStatus;
use App\Enums\PlannedTransactionType;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\User;

uses(Tests\Concerns\CallsApi::class);

// New plans must start after today, so the fixed dates below need a fixed clock.
beforeEach(fn () => $this->travelTo('2026-10-15'));

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function plannedWritePayload(User $user, array $overrides = []): array
{
    return [
        'account_id' => Account::factory()->for($user)->create()->id,
        'amount' => 12_345,
        'direction' => 'debit',
        'description' => 'Gym Membership',
        'start_date' => '2026-12-01',
        'frequency' => 'every-month',
        ...$overrides,
    ];
}

it('creates a planned transaction storing integer cents', function (): void {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->for($user)->create();

    $this->apiCall('POST', 'planned-transactions', $this->apiTokenFor($user), plannedWritePayload($user, [
        'account_id' => $account->id,
        'category_id' => $category->id,
        'type' => 'subscription',
        'autopay' => true,
        'notes' => 'Annual review',
        'until_date' => '2027-12-01',
    ]))
        ->assertCreated()
        ->assertJsonPath('data.amount', 12_345)
        ->assertJsonPath('data.type', 'subscription')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.start_date', '2026-12-01')
        ->assertJsonPath('data.until_date', '2027-12-01');

    $plan = PlannedTransaction::query()->where('user_id', $user->id)->sole();

    expect($plan->amount)->toBe(12_345)
        ->and($plan->account_id)->toBe($account->id)
        ->and($plan->category_id)->toBe($category->id)
        ->and($plan->direction)->toBe(TransactionDirection::Debit)
        ->and($plan->type)->toBe(PlannedTransactionType::Subscription)
        ->and($plan->frequency)->toBe(RecurrenceFrequency::EveryMonth)
        ->and($plan->autopay)->toBeTrue()
        ->and($plan->notes)->toBe('Annual review')
        ->and($plan->status)->toBe(PlannedTransactionStatus::Active);
});

it('defaults the type from the direction', function (): void {
    $user = User::factory()->create();
    $token = $this->apiTokenFor($user);

    $this->apiCall('POST', 'planned-transactions', $token, plannedWritePayload($user, ['direction' => 'credit', 'description' => 'Salary']))
        ->assertCreated()
        ->assertJsonPath('data.type', 'income');

    $this->apiCall('POST', 'planned-transactions', $token, plannedWritePayload($user))
        ->assertCreated()
        ->assertJsonPath('data.type', 'bill');
});

it('returns 409 when creating a duplicate recurring series', function (): void {
    $user = User::factory()->create();
    $token = $this->apiTokenFor($user);
    $payload = plannedWritePayload($user);

    $this->apiCall('POST', 'planned-transactions', $token, $payload)->assertCreated();
    $this->apiCall('POST', 'planned-transactions', $token, [...$payload, 'description' => 'gym membership'])
        ->assertConflict()
        ->assertJsonPath('message', 'A plan like this already exists.');
    $this->apiCall('POST', 'planned-transactions', $token, [...$payload, 'amount' => 999])->assertCreated();

    expect(PlannedTransaction::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('accepts only active and inactive recurring statuses', function (): void {
    $user = User::factory()->create();
    $token = $this->apiTokenFor($user);

    $this->apiCall('POST', 'planned-transactions', $token, plannedWritePayload($user, ['status' => 'inactive']))
        ->assertCreated()
        ->assertJsonPath('data.status', 'inactive');

    foreach (['ended', 'paused', 'archived', ''] as $status) {
        $this->apiCall('POST', 'planned-transactions', $token, plannedWritePayload($user, ['status' => $status, 'description' => "Plan {$status}"]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    $plan = PlannedTransaction::factory()->for($user)->for(Account::factory()->for($user), 'account')->create();

    $this->apiCall('PATCH', "planned-transactions/{$plan->id}", $token, ['status' => 'ended'])->assertUnprocessable()->assertJsonValidationErrors('status');
    expect($plan->refresh()->status)->toBe(PlannedTransactionStatus::Active);

    $this->apiCall('PATCH', "planned-transactions/{$plan->id}", $token, ['status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');
    $this->apiCall('PATCH', "planned-transactions/{$plan->id}", $token, ['status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active');
});

it('rejects foreign account and category ids with 422 and creates nothing', function (): void {
    $user = User::factory()->create();
    $foreignAccount = Account::factory()->for(User::factory()->create())->create();
    $foreignCategory = Category::factory()->for(User::factory()->create())->create();
    $token = $this->apiTokenFor($user);

    $this->apiCall('POST', 'planned-transactions', $token, plannedWritePayload($user, ['account_id' => $foreignAccount->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account_id');
    $this->apiCall('POST', 'planned-transactions', $token, plannedWritePayload($user, ['category_id' => $foreignCategory->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('category_id');

    expect(PlannedTransaction::query()->count())->toBe(0);
});

it('rejects invalid plan fields with 422', function (): void {
    $user = User::factory()->create();
    $token = $this->apiTokenFor($user);

    foreach ([
        ['amount', 0],
        ['amount', -5],
        ['amount', 12.5],
        ['direction', 'sideways'],
        ['frequency', 'two-on-two-off'],
        ['frequency', 'fortnightly'],
        ['start_date', '01/12/2026'],
        ['until_date', '2026-11-01'],
        ['type', 'transfer'],
        ['type', 'income'],
        ['recurrence_interval', 5],
    ] as [$field, $value]) {
        $this->apiCall('POST', 'planned-transactions', $token, plannedWritePayload($user, [$field => $value]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    expect(PlannedTransaction::query()->count())->toBe(0);
});

it('stores the interval for an every-n-weeks series', function (): void {
    $user = User::factory()->create();

    $this->apiCall('POST', 'planned-transactions', $this->apiTokenFor($user), plannedWritePayload($user, ['frequency' => 'every-n-weeks', 'recurrence_interval' => 6]))
        ->assertCreated()
        ->assertJsonPath('data.recurrence_interval', 6);
});

it('updates a plan with PUT and PATCH', function (): void {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $plan = PlannedTransaction::factory()->for($user)->for($account)->create(['amount' => 1_000, 'description' => 'Old']);
    $token = $this->apiTokenFor($user);

    $this->apiCall('PUT', "planned-transactions/{$plan->id}", $token, plannedWritePayload($user, ['account_id' => $account->id, 'amount' => 2_500, 'description' => 'Renamed']))
        ->assertOk()
        ->assertJsonPath('data.amount', 2_500)
        ->assertJsonPath('data.description', 'Renamed');

    $this->apiCall('PATCH', "planned-transactions/{$plan->id}", $token, ['autopay' => true])->assertOk();

    expect($plan->refresh()->amount)->toBe(2_500)
        ->and($plan->description)->toBe('Renamed')
        ->and($plan->autopay)->toBeTrue();
});

it('answers 404 and leaves another user plan unchanged on update and delete', function (): void {
    $user = User::factory()->create();
    $foreign = PlannedTransaction::factory()->create(['amount' => 1_000, 'description' => 'Theirs']);
    $token = $this->apiTokenFor($user);

    $this->apiCall('PATCH', "planned-transactions/{$foreign->id}", $token, ['amount' => 5])->assertNotFound();
    $this->apiCall('DELETE', "planned-transactions/{$foreign->id}", $token)->assertNotFound();

    expect($foreign->refresh()->amount)->toBe(1_000)
        ->and($foreign->description)->toBe('Theirs');
});

it('deletes a plan', function (): void {
    $user = User::factory()->create();
    $plan = PlannedTransaction::factory()->for($user)->for(Account::factory()->for($user), 'account')->create();

    $this->apiCall('DELETE', "planned-transactions/{$plan->id}", $this->apiTokenFor($user))->assertNoContent();

    expect(PlannedTransaction::query()->whereKey($plan->id)->exists())->toBeFalse();
});

it('refuses planned transaction writes from a read-only key with 403 and changes nothing', function (): void {
    $user = User::factory()->create();
    $plan = PlannedTransaction::factory()->for($user)->for(Account::factory()->for($user), 'account')->create(['amount' => 1_000]);
    $token = $this->apiTokenFor($user, ApiKeyScope::Read);

    $this->apiCall('POST', 'planned-transactions', $token, plannedWritePayload($user))->assertForbidden();
    $this->apiCall('PATCH', "planned-transactions/{$plan->id}", $token, ['amount' => 5])->assertForbidden();
    $this->apiCall('DELETE', "planned-transactions/{$plan->id}", $token)->assertForbidden();

    expect(PlannedTransaction::query()->count())->toBe(1)
        ->and($plan->refresh()->amount)->toBe(1_000);
});

it('answers 404 for planned transaction writes when recurring is switched off', function (): void {
    $user = User::factory()->create();
    $user->updatePreferences(['recurringEnabled' => false]);
    $plan = PlannedTransaction::factory()->for($user)->for(Account::factory()->for($user), 'account')->create(['amount' => 1_000]);
    $token = $this->apiTokenFor($user);

    $this->apiCall('POST', 'planned-transactions', $token, plannedWritePayload($user))->assertNotFound();
    $this->apiCall('PATCH', "planned-transactions/{$plan->id}", $token, ['amount' => 5])->assertNotFound();
    $this->apiCall('DELETE', "planned-transactions/{$plan->id}", $token)->assertNotFound();

    expect(PlannedTransaction::query()->count())->toBe(1)
        ->and($plan->refresh()->amount)->toBe(1_000);
});

it('refuses a planned transaction on an untracked account', function (): void {
    $user = User::factory()->create();
    $untracked = Account::factory()->for($user)->create(['is_tracked' => false]);

    $this->apiCall('POST', 'planned-transactions', $this->apiTokenFor($user), plannedWritePayload($user, ['account_id' => $untracked->id]))->assertUnprocessable();

    expect(PlannedTransaction::query()->count())->toBe(0);
});

it('answers 422 instead of 500 for array input in typed planned fields', function (string $field): void {
    $user = User::factory()->create();

    $this->apiCall('POST', 'planned-transactions', $this->apiTokenFor($user), plannedWritePayload($user, [$field => ['x']]))->assertUnprocessable();
})->with(['direction', 'frequency', 'type']);

it('keeps the start date before the existing end date when only the start changes', function (): void {
    $user = User::factory()->create();
    $token = $this->apiTokenFor($user);
    $id = $this->apiCall('POST', 'planned-transactions', $token, plannedWritePayload($user, ['start_date' => '2027-01-01', 'until_date' => '2027-12-01']))->assertCreated()->json('data.id');

    $this->apiCall('PATCH', 'planned-transactions/'.$id, $token, ['start_date' => '2028-01-01'])->assertUnprocessable();

    expect(PlannedTransaction::query()->findOrFail($id)->start_date->toDateString())->toBe('2027-01-01');
});

it('clears the days of month when the frequency changes', function (): void {
    $user = User::factory()->create();
    $token = $this->apiTokenFor($user);
    $id = $this->apiCall('POST', 'planned-transactions', $token, plannedWritePayload($user, ['frequency' => 'every-month']))->assertCreated()->json('data.id');
    PlannedTransaction::query()->whereKey($id)->update(['days_of_month' => json_encode([5])]);

    $this->apiCall('PATCH', 'planned-transactions/'.$id, $token, ['frequency' => 'semimonthly'])->assertOk();

    expect(PlannedTransaction::query()->findOrFail($id)->days_of_month)->not->toBe([5]);
});
