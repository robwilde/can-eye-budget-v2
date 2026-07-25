<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BnplOrderStatus;
use App\Enums\BnplProvider;
use App\Enums\RecurrenceFrequency;
use App\Models\Account;
use App\Models\BnplOrder;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

test('factory creates a valid pending order', function () {
    $order = BnplOrder::factory()->create();

    expect($order)->toBeInstanceOf(BnplOrder::class)
        ->and($order->exists)->toBeTrue()
        ->and($order->status)->toBe(BnplOrderStatus::PendingReview)
        ->and($order->category_id)->toBeNull();
});

test('provider status and frequency are cast to enums', function () {
    $order = BnplOrder::factory()->create();

    expect($order->provider)->toBeInstanceOf(BnplProvider::class)
        ->and($order->status)->toBeInstanceOf(BnplOrderStatus::class)
        ->and($order->frequency)->toBe(RecurrenceFrequency::Every2Weeks);
});

test('frequency is null for an unsupported cadence', function () {
    $order = BnplOrder::factory()->create([
        'frequency' => null,
        'review_note' => 'unsupported_cadence',
    ]);

    expect($order->fresh()->frequency)->toBeNull()
        ->and($order->fresh()->review_note)->toBe('unsupported_cadence');
});

test('total and instalment amount are stored as whole cents', function () {
    $order = BnplOrder::factory()->create(['total' => 7445, 'instalment_amount' => 1861]);

    expect($order->fresh()->total)->toBe(7445)
        ->and($order->fresh()->instalment_amount)->toBe(1861);
});

test('parsed_payload is cast to array', function () {
    $payload = ['orderRef' => '953186001', 'instalments' => [['date' => '2026-08-07', 'amount' => 1861]]];
    $order = BnplOrder::factory()->create(['parsed_payload' => $payload]);

    expect($order->fresh()->parsed_payload)->toBe($payload);
});

test('belongs to a user, account, category and planned transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create();
    $plan = PlannedTransaction::factory()->for($user)->for($account)->create();

    $order = BnplOrder::factory()->for($user)->create([
        'account_id' => $account->id,
        'category_id' => $category->id,
        'planned_transaction_id' => $plan->id,
    ]);

    expect($order->user->id)->toBe($user->id)
        ->and($order->account->id)->toBe($account->id)
        ->and($order->category->id)->toBe($category->id)
        ->and($order->plannedTransaction->id)->toBe($plan->id);
});

test('the default factory scopes the account to the order user', function () {
    $order = BnplOrder::factory()->create();

    expect($order->account->user_id)->toBe($order->user_id);
});

test('for() attaches an account owned by the same user', function () {
    $user = User::factory()->create();

    $order = BnplOrder::factory()->for($user)->create();

    expect($order->user_id)->toBe($user->id)
        ->and($order->account->user_id)->toBe($user->id);
});

test('cascades on user delete', function () {
    $user = User::factory()->create();
    BnplOrder::factory()->for($user)->create();

    $user->delete();

    expect(BnplOrder::query()->count())->toBe(0);
});

test('survives account deletion with a null account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $order = BnplOrder::factory()->for($user)->create(['account_id' => $account->id]);

    $account->delete();

    expect($order->fresh()->account_id)->toBeNull();
});

test('survives planned transaction deletion with a null link', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $plan = PlannedTransaction::factory()->for($user)->for($account)->create();
    $order = BnplOrder::factory()->for($user)->create(['planned_transaction_id' => $plan->id]);

    $plan->delete();

    expect($order->fresh()->planned_transaction_id)->toBeNull();
});

test('rejects a second order for the same gmail message', function () {
    $user = User::factory()->create();
    BnplOrder::factory()->for($user)->create(['gmail_message_id' => 'abc@afterpay.com']);

    BnplOrder::factory()->for($user)->create(['gmail_message_id' => 'abc@afterpay.com']);
})->throws(UniqueConstraintViolationException::class);

test('rejects the same order arriving under a second message id', function () {
    $user = User::factory()->create();
    BnplOrder::factory()->for($user)->create([
        'provider' => BnplProvider::Afterpay,
        'order_ref' => '953186001',
        'gmail_message_id' => 'first@afterpay.com',
    ]);

    BnplOrder::factory()->for($user)->create([
        'provider' => BnplProvider::Afterpay,
        'order_ref' => '953186001',
        'gmail_message_id' => 'second@afterpay.com',
    ]);
})->throws(UniqueConstraintViolationException::class);

test('two users may hold the same order ref independently', function () {
    BnplOrder::factory()->create(['order_ref' => '953186001']);
    $second = BnplOrder::factory()->create(['order_ref' => '953186001']);

    expect($second->exists)->toBeTrue()
        ->and(BnplOrder::query()->count())->toBe(2);
});

test('isSettled is true when the last due date is yesterday', function () {
    $order = BnplOrder::factory()->create([
        'last_due_date' => CarbonImmutable::today()->subDay(),
    ]);

    expect($order->isSettled())->toBeTrue();
});

test('isSettled is false when the last due date is today', function () {
    $order = BnplOrder::factory()->create([
        'last_due_date' => CarbonImmutable::today(),
    ]);

    expect($order->isSettled())->toBeFalse();
});

test('isSettled is false when the last due date is in the future', function () {
    $order = BnplOrder::factory()->create([
        'last_due_date' => CarbonImmutable::today()->addWeeks(6),
    ]);

    expect($order->isSettled())->toBeFalse();
});

test('the settled factory state produces a settled order', function () {
    $order = BnplOrder::factory()->settled()->create();

    expect($order->isSettled())->toBeTrue();
});

test('auto approved state carries a category and a review timestamp', function () {
    $order = BnplOrder::factory()->autoApproved()->create();

    expect($order->status)->toBe(BnplOrderStatus::AutoApproved)
        ->and($order->category_id)->not->toBeNull()
        ->and($order->reviewed_at)->not->toBeNull();
});
