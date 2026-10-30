<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Enums\TransactionStatus;
use App\Models\Transaction;

function childEnrichData(): array
{
    return [
        'redbark' => ['merchantCategoryCode' => '5411', 'category' => 'Groceries'],
        'location' => ['suburb' => 'Bondi'],
    ];
}

test('an edited row keeps enrich_data as the same array', function () {
    $parent = Transaction::factory()->create(['enrich_data' => childEnrichData()]);

    $child = $parent->createChild(['notes' => 'edited'])->fresh();

    expect($child->enrich_data)->toBe(childEnrichData())
        ->and($child->enrich_data['location']['suburb'])->toBe('Bondi')
        ->and($child->enrich_data['redbark']['merchantCategoryCode'])->toBe('5411');
});

test('a null enrich_data stays null on the child', function () {
    $parent = Transaction::factory()->create(['enrich_data' => null]);

    expect($parent->createChild(['notes' => 'edited'])->fresh()->enrich_data)->toBeNull();
});

test('a second generation child keeps enrich_data as an array', function () {
    $parent = Transaction::factory()->create(['enrich_data' => childEnrichData()]);

    $grandchild = $parent->createChild(['notes' => 'first'])->createChild(['notes' => 'second'])->fresh();

    expect($grandchild->enrich_data)->toBe(childEnrichData());
});

test('an override of a json attribute wins over the copied value', function () {
    $parent = Transaction::factory()->create(['enrich_data' => childEnrichData()]);

    expect($parent->createChild(['enrich_data' => ['redbark' => ['category' => 'Fuel']]])->fresh()->enrich_data)->toBe(['redbark' => ['category' => 'Fuel']]);
});

test('enum, date and money casts still round trip through a child', function () {
    $parent = Transaction::factory()->create([
        'direction' => TransactionDirection::Credit,
        'status' => TransactionStatus::Pending,
        'post_date' => '2026-08-10',
        'amount' => 4250,
    ]);

    $child = $parent->createChild(['notes' => 'edited'])->fresh();

    expect($child->direction)->toBe(TransactionDirection::Credit)
        ->and($child->status)->toBe(TransactionStatus::Pending)
        ->and($child->post_date->toDateString())->toBe('2026-08-10')
        ->and($child->amount)->toBe(4250);
});
