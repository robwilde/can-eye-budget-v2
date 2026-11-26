<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ApiKeyScope;
use App\Enums\PayeeStatus;
use App\Models\Category;
use App\Models\Payee;
use App\Models\User;
use App\Models\UserRule;

uses(Tests\Concerns\CallsApi::class);

it('records a payee with a confirmed category', function (): void {
    $user = User::factory()->create();
    $category = Category::factory()->for($user)->create();

    $this->apiCall('POST', 'payees', $this->apiTokenFor($user), ['merchant_name' => 'Netflix', 'category_id' => $category->id])
        ->assertCreated()
        ->assertJsonPath('data.status', PayeeStatus::Confirmed->value)
        ->assertJsonPath('data.confirmed_category_id', $category->id);

    $payee = Payee::query()->where('user_id', $user->id)->sole();

    expect($payee->status)->toBe(PayeeStatus::Confirmed)
        ->and($payee->merchant_name)->toBe('Netflix')
        ->and($payee->user_rule_id)->not->toBeNull()
        ->and(UserRule::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('confirms an existing payee for the same merchant instead of duplicating it', function (): void {
    $user = User::factory()->create();
    $first = Category::factory()->for($user)->create(['name' => 'Streaming']);
    $second = Category::factory()->for($user)->create(['name' => 'Entertainment']);
    $token = $this->apiTokenFor($user);

    $this->apiCall('POST', 'payees', $token, ['merchant_name' => 'Netflix', 'category_id' => $first->id])->assertCreated();
    $this->apiCall('POST', 'payees', $token, ['merchant_name' => 'NETFLIX', 'category_id' => $second->id])
        ->assertOk()
        ->assertJsonPath('data.confirmed_category_id', $second->id);

    expect(Payee::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(UserRule::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('rejects an unknown or foreign category with 422 and records nothing', function (): void {
    $user = User::factory()->create();
    $foreign = Category::factory()->for(User::factory()->create())->create();
    $token = $this->apiTokenFor($user);

    $this->apiCall('POST', 'payees', $token, ['merchant_name' => 'Netflix', 'category_id' => $foreign->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('category_id');
    $this->apiCall('POST', 'payees', $token, ['merchant_name' => 'Netflix', 'category_id' => 999999])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('category_id');

    expect(Payee::query()->count())->toBe(0);
});

it('refuses payee writes from a read-only key with 403 and changes nothing', function (): void {
    $user = User::factory()->create();
    $category = Category::factory()->for($user)->create();

    $this->apiCall('POST', 'payees', $this->apiTokenFor($user, ApiKeyScope::Read), ['merchant_name' => 'Netflix', 'category_id' => $category->id])
        ->assertForbidden();

    expect(Payee::query()->count())->toBe(0);
});
