<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Transaction;

it('clusters two PayPal Steam rows with different reference numbers under one key', function () {
    $a = Transaction::factory()->create([
        'merchant_name' => null,
        'clean_description' => null,
        'description' => 'PAYPAL *STEAM 4829',
    ]);

    $b = Transaction::factory()->create([
        'merchant_name' => null,
        'clean_description' => null,
        'description' => 'PAYPAL *STEAM 5561',
    ]);

    expect($a->merchant_key)->toBe('PAYPAL STEAM')
        ->and($b->merchant_key)->toBe($a->merchant_key);
});

it('keeps a different PayPal sub-merchant in its own cluster', function () {
    $steam = Transaction::factory()->create([
        'merchant_name' => null,
        'clean_description' => null,
        'description' => 'PAYPAL *STEAM 4829',
    ]);

    $cloudns = Transaction::factory()->create([
        'merchant_name' => null,
        'clean_description' => null,
        'description' => 'PAYPAL *CLOUDNS',
    ]);

    expect($cloudns->merchant_key)->toBe('PAYPAL CLOUDNS')
        ->and($cloudns->merchant_key)->not->toBe($steam->merchant_key);
});

it('falls through an empty merchant_name to the description rather than bucketing as unknown', function () {
    $transaction = Transaction::factory()->create([
        'merchant_name' => '',
        'clean_description' => null,
        'description' => 'NETFLIX.COM',
    ]);

    expect($transaction->merchant_key)->toBe('NETFLIX.COM');
});

it('prefers merchant_name over the raw description when both are present', function () {
    $transaction = Transaction::factory()->create([
        'merchant_name' => 'Woolworths',
        'clean_description' => null,
        'description' => 'EFTPOS WOOLWORTHS 1234 SYDNEY',
    ]);

    expect($transaction->merchant_key)->toBe('WOOLWORTHS');
});

it('buckets a row with no usable description under the unknown sentinel', function () {
    $transaction = Transaction::factory()->create([
        'merchant_name' => '',
        'clean_description' => '',
        'description' => '   ',
    ]);

    expect($transaction->merchant_key)->toBe(Transaction::UNKNOWN_MERCHANT_KEY);
});

it('recomputes the key when the description is edited', function () {
    $transaction = Transaction::factory()->create([
        'merchant_name' => null,
        'clean_description' => null,
        'description' => 'NETFLIX.COM',
    ]);

    expect($transaction->merchant_key)->toBe('NETFLIX.COM');

    $transaction->update(['description' => 'SPOTIFY P0A1B2C3']);

    expect($transaction->fresh()->merchant_key)->toBe('SPOTIFY');
});

it('does not carry the parent key onto a child created with a new description', function () {
    $parent = Transaction::factory()->create([
        'merchant_name' => null,
        'clean_description' => null,
        'description' => 'NETFLIX.COM',
    ]);

    $child = $parent->createChild(['description' => 'DISNEY PLUS']);

    expect($child->merchant_key)->toBe('DISNEY PLUS');
});
