<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BnplOrderEventType;
use App\Enums\BnplOrderStatus;

test('isApproved covers both the reviewed and the automatic path', function () {
    expect(BnplOrderStatus::Approved->isApproved())->toBeTrue()
        ->and(BnplOrderStatus::AutoApproved->isApproved())->toBeTrue();
});

test('isApproved is false for pending and rejected orders', function () {
    expect(BnplOrderStatus::PendingReview->isApproved())->toBeFalse()
        ->and(BnplOrderStatus::Rejected->isApproved())->toBeFalse();
});

test('every status has a label', function () {
    foreach (BnplOrderStatus::cases() as $status) {
        expect($status->label())->not->toBe('');
    }
});

test('the seven audit events the spec names all exist', function () {
    $values = array_map(fn (BnplOrderEventType $e): string => $e->value, BnplOrderEventType::cases());

    expect($values)->toEqualCanonicalizing([
        'email_pulled',
        'plan_created',
        'review_requested',
        'approved',
        'auto_approved',
        'category_set',
        'rejected',
    ]);
});
