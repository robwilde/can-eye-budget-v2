<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BnplOrderEventType;
use App\Enums\BnplOrderStatus;
use App\Livewire\BnplOrderReview;
use App\Livewire\BnplPendingBadge;
use App\Models\BnplOrder;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    config(['budget.bnpl_email_import' => true]);
});

function bnplPlanFor(User $user, array $overrides = []): PlannedTransaction
{
    return PlannedTransaction::factory()->create(array_merge(['user_id' => $user->id], $overrides));
}

function bnplEventNames(BnplOrder $order): array
{
    return $order->events()->orderBy('id')->get()->map(fn ($event) => $event->event)->all();
}

test('pending orders are listed grouped by retailer', function () {
    $user = User::factory()->create();

    BnplOrder::factory()->create(['user_id' => $user->id, 'retailer' => 'Petbarn']);
    BnplOrder::factory()->create(['user_id' => $user->id, 'retailer' => 'Petbarn']);
    BnplOrder::factory()->create(['user_id' => $user->id, 'retailer' => 'Kmart']);
    BnplOrder::factory()->approved()->create(['user_id' => $user->id, 'retailer' => 'Already Approved Pty']);

    $component = Livewire::actingAs($user)->test(BnplOrderReview::class);

    $component->assertSee('Petbarn')->assertSee('Kmart')->assertDontSee('Already Approved Pty');

    $groups = $component->instance()->pendingOrders;
    expect($groups->keys()->all())->toBe(['Kmart', 'Petbarn'])
        ->and($groups['Petbarn'])->toHaveCount(2);
});

test('approving with a category records the decision on the order and its plan', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();
    $plan = bnplPlanFor($user, ['category_id' => null]);
    $order = BnplOrder::factory()->create([
        'user_id' => $user->id,
        'planned_transaction_id' => $plan->id,
    ]);

    Livewire::actingAs($user)
        ->test(BnplOrderReview::class)
        ->set("categories.{$order->id}", $category->id)
        ->call('approve', $order->id)
        ->assertHasNoErrors();

    $order->refresh();
    expect($order->status)->toBe(BnplOrderStatus::Approved)
        ->and($order->category_id)->toBe($category->id)
        ->and($order->reviewed_at)->not->toBeNull()
        ->and($plan->refresh()->category_id)->toBe($category->id)
        ->and(bnplEventNames($order))->toBe([BnplOrderEventType::CategorySet, BnplOrderEventType::Approved])
        ->and($order->events()->pluck('actor')->unique()->all())->toBe([(string) $user->id]);
});

test('approving without a category adds an error and changes nothing', function () {
    $user = User::factory()->create();
    $order = BnplOrder::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(BnplOrderReview::class)
        ->call('approve', $order->id)
        ->assertHasErrors(["categories.{$order->id}"]);

    $order->refresh();
    expect($order->status)->toBe(BnplOrderStatus::PendingReview)
        ->and($order->category_id)->toBeNull()
        ->and($order->reviewed_at)->toBeNull()
        ->and($order->events()->count())->toBe(0);
});

test('rejecting marks the order rejected and deactivates its plan', function () {
    $user = User::factory()->create();
    $plan = bnplPlanFor($user);
    $order = BnplOrder::factory()->create([
        'user_id' => $user->id,
        'planned_transaction_id' => $plan->id,
    ]);

    Livewire::actingAs($user)
        ->test(BnplOrderReview::class)
        ->call('reject', $order->id);

    $order->refresh();
    expect($order->status)->toBe(BnplOrderStatus::Rejected)
        ->and($order->reviewed_at)->not->toBeNull()
        ->and($plan->refresh()->is_active)->toBeFalse()
        ->and(bnplEventNames($order))->toBe([BnplOrderEventType::Rejected])
        ->and($order->events()->value('actor'))->toBe((string) $user->id);
});

test('recategorising an auto-approved order updates the order and its plan', function () {
    $user = User::factory()->create();
    $newCategory = Category::factory()->create();
    $plan = bnplPlanFor($user);
    $order = BnplOrder::factory()->autoApproved()->create([
        'user_id' => $user->id,
        'planned_transaction_id' => $plan->id,
    ]);

    Livewire::actingAs($user)
        ->test(BnplOrderReview::class)
        ->assertSee($order->retailer)
        ->set("categories.{$order->id}", $newCategory->id)
        ->call('recategorise', $order->id);

    $order->refresh();
    expect($order->status)->toBe(BnplOrderStatus::AutoApproved)
        ->and($order->category_id)->toBe($newCategory->id)
        ->and($plan->refresh()->category_id)->toBe($newCategory->id)
        ->and(bnplEventNames($order))->toBe([BnplOrderEventType::CategorySet]);
});

test('an order belonging to another user is not listed and cannot be approved', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $category = Category::factory()->create();
    $order = BnplOrder::factory()->create(['user_id' => $owner->id, 'retailer' => 'Owner Only Retailer']);

    Livewire::actingAs($intruder)
        ->test(BnplOrderReview::class)
        ->assertDontSee('Owner Only Retailer')
        ->set("categories.{$order->id}", $category->id)
        ->call('approve', $order->id)
        ->assertNotFound();

    expect($order->refresh()->status)->toBe(BnplOrderStatus::PendingReview);
});

test('approving an order that has no plan and is not settled is refused', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();
    $order = BnplOrder::factory()->create([
        'user_id' => $user->id,
        'planned_transaction_id' => null,
        'review_note' => BnplOrder::REVIEW_NOTE_UNSUPPORTED_CADENCE,
    ]);

    Livewire::actingAs($user)
        ->test(BnplOrderReview::class)
        ->set("categories.{$order->id}", $category->id)
        ->call('approve', $order->id)
        ->assertHasErrors("categories.{$order->id}");

    expect($order->refresh()->status)->toBe(BnplOrderStatus::PendingReview);
});

test('nothing renders and the sidebar shows no badge while the flag is off', function () {
    config(['budget.bnpl_email_import' => false]);
    $user = User::factory()->create();
    BnplOrder::factory()->create(['user_id' => $user->id, 'retailer' => 'Hidden Retailer']);

    Livewire::actingAs($user)
        ->test(BnplOrderReview::class)
        ->assertDontSee('Hidden Retailer')
        ->assertDontSee('Buy now pay later');

    Livewire::actingAs($user)
        ->test(BnplPendingBadge::class)
        ->assertDontSeeHtml('data-testid="bnpl-pending-badge"');
});

test('the rules sidebar badge shows the number of orders awaiting review', function () {
    $user = User::factory()->create();
    BnplOrder::factory()->count(3)->create(['user_id' => $user->id]);
    BnplOrder::factory()->approved()->create(['user_id' => $user->id]);
    BnplOrder::factory()->create();

    Livewire::actingAs($user)
        ->test(BnplPendingBadge::class)
        ->assertSeeHtml('data-testid="bnpl-pending-badge"')
        ->assertSeeInOrder(['bnpl-pending-badge', '3']);

    $this->actingAs($user)->get(route('rules'))->assertOk()->assertSeeHtml('data-testid="bnpl-pending-badge"');
});

test('the badge follows the pending count after a review action', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();
    $plan = bnplPlanFor($user);
    $order = BnplOrder::factory()->create(['user_id' => $user->id, 'planned_transaction_id' => $plan->id]);

    $review = Livewire::actingAs($user)
        ->test(BnplOrderReview::class)
        ->set("categories.{$order->id}", $category->id)
        ->call('approve', $order->id)
        ->assertDispatched('bnpl-orders-reviewed');

    Livewire::actingAs($user)
        ->test(BnplPendingBadge::class)
        ->assertDontSeeHtml('data-testid="bnpl-pending-badge"');

    expect($review->instance()->pendingOrders)->toBeEmpty();
});
