<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BnplOrderEventType;

test('bnpl order event type has correct backing values', function () {
    expect(BnplOrderEventType::EmailPulled->value)->toBe('email_pulled')
        ->and(BnplOrderEventType::PlanCreated->value)->toBe('plan_created')
        ->and(BnplOrderEventType::ReviewRequested->value)->toBe('review_requested')
        ->and(BnplOrderEventType::Approved->value)->toBe('approved')
        ->and(BnplOrderEventType::AutoApproved->value)->toBe('auto_approved')
        ->and(BnplOrderEventType::CategorySet->value)->toBe('category_set')
        ->and(BnplOrderEventType::Rejected->value)->toBe('rejected')
        ->and(BnplOrderEventType::PaymentLinked->value)->toBe('payment_linked');
});

test('the audit events all exist', function () {
    $values = array_map(fn (BnplOrderEventType $e): string => $e->value, BnplOrderEventType::cases());

    expect($values)->toEqualCanonicalizing([
        'email_pulled',
        'plan_created',
        'review_requested',
        'approved',
        'auto_approved',
        'category_set',
        'rejected',
        'payment_linked',
    ]);
});
