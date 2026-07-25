<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BnplOrderEventType;
use App\Models\BnplOrder;
use App\Models\BnplOrderEvent;

test('factory creates a valid event', function () {
    $event = BnplOrderEvent::factory()->create();

    expect($event)->toBeInstanceOf(BnplOrderEvent::class)
        ->and($event->exists)->toBeTrue();
});

test('event is cast to an enum and payload to an array', function () {
    $event = BnplOrderEvent::factory()->create([
        'event' => BnplOrderEventType::PlanCreated,
        'payload' => ['planned_transaction_id' => 12],
    ]);

    expect($event->fresh()->event)->toBe(BnplOrderEventType::PlanCreated)
        ->and($event->fresh()->payload)->toBe(['planned_transaction_id' => 12]);
});

test('belongs to an order', function () {
    $order = BnplOrder::factory()->create();
    $event = BnplOrderEvent::factory()->for($order)->create();

    expect($event->bnplOrder->id)->toBe($order->id);
});

test('cascades on order delete', function () {
    $order = BnplOrder::factory()->create();
    BnplOrderEvent::factory()->for($order)->create();

    $order->delete();

    expect(BnplOrderEvent::query()->count())->toBe(0);
});

test('recordEvent writes one row attributed to the system by default', function () {
    $order = BnplOrder::factory()->create();

    $event = $order->recordEvent(BnplOrderEventType::EmailPulled);

    expect($event->event)->toBe(BnplOrderEventType::EmailPulled)
        ->and($event->actor)->toBe('system')
        ->and($event->payload)->toBeNull()
        ->and($order->events()->count())->toBe(1);
});

test('recordEvent stores a payload and an explicit actor', function () {
    $order = BnplOrder::factory()->create();

    $event = $order->recordEvent(BnplOrderEventType::CategorySet, ['category_id' => 7], '3');

    expect($event->payload)->toBe(['category_id' => 7])
        ->and($event->actor)->toBe('3');
});

test('an order accumulates its events in write order', function () {
    $order = BnplOrder::factory()->create();

    $order->recordEvent(BnplOrderEventType::EmailPulled);
    $order->recordEvent(BnplOrderEventType::ReviewRequested);

    $events = $order->events()->orderBy('id')->pluck('event')->all();

    expect($events)->toBe([BnplOrderEventType::EmailPulled, BnplOrderEventType::ReviewRequested]);
});
