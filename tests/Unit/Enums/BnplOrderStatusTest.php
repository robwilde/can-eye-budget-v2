<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BnplOrderStatus;

test('all bnpl order status cases exist', function () {
    expect(BnplOrderStatus::cases())->toHaveCount(4);
});

test('bnpl order status has correct backing values', function () {
    expect(BnplOrderStatus::PendingReview->value)->toBe('pending_review')
        ->and(BnplOrderStatus::Approved->value)->toBe('approved')
        ->and(BnplOrderStatus::AutoApproved->value)->toBe('auto_approved')
        ->and(BnplOrderStatus::Rejected->value)->toBe('rejected');
});

test('bnpl order status resolves from backing value', function () {
    expect(BnplOrderStatus::from('pending_review'))->toBe(BnplOrderStatus::PendingReview)
        ->and(BnplOrderStatus::from('approved'))->toBe(BnplOrderStatus::Approved)
        ->and(BnplOrderStatus::from('auto_approved'))->toBe(BnplOrderStatus::AutoApproved)
        ->and(BnplOrderStatus::from('rejected'))->toBe(BnplOrderStatus::Rejected);
});

test('bnpl order status has labels', function () {
    expect(BnplOrderStatus::PendingReview->label())->toBe('Pending review')
        ->and(BnplOrderStatus::Approved->label())->toBe('Approved')
        ->and(BnplOrderStatus::AutoApproved->label())->toBe('Auto-approved')
        ->and(BnplOrderStatus::Rejected->label())->toBe('Rejected');
});

test('isApproved covers both the reviewed and the automatic path', function () {
    expect(BnplOrderStatus::Approved->isApproved())->toBeTrue()
        ->and(BnplOrderStatus::AutoApproved->isApproved())->toBeTrue();
});

test('isApproved is false for pending and rejected orders', function () {
    expect(BnplOrderStatus::PendingReview->isApproved())->toBeFalse()
        ->and(BnplOrderStatus::Rejected->isApproved())->toBeFalse();
});
