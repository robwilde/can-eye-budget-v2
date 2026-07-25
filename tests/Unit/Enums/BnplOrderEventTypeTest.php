<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BnplOrderEventType;

test('all bnpl order event type cases exist', function () {
    expect(BnplOrderEventType::cases())->toHaveCount(7);
});

test('bnpl order event type has correct backing values', function () {
    expect(BnplOrderEventType::EmailPulled->value)->toBe('email_pulled')
        ->and(BnplOrderEventType::PlanCreated->value)->toBe('plan_created')
        ->and(BnplOrderEventType::ReviewRequested->value)->toBe('review_requested')
        ->and(BnplOrderEventType::Approved->value)->toBe('approved')
        ->and(BnplOrderEventType::AutoApproved->value)->toBe('auto_approved')
        ->and(BnplOrderEventType::CategorySet->value)->toBe('category_set')
        ->and(BnplOrderEventType::Rejected->value)->toBe('rejected');
});

test('bnpl order event type resolves from backing value', function () {
    expect(BnplOrderEventType::from('email_pulled'))->toBe(BnplOrderEventType::EmailPulled)
        ->and(BnplOrderEventType::from('plan_created'))->toBe(BnplOrderEventType::PlanCreated)
        ->and(BnplOrderEventType::from('review_requested'))->toBe(BnplOrderEventType::ReviewRequested)
        ->and(BnplOrderEventType::from('approved'))->toBe(BnplOrderEventType::Approved)
        ->and(BnplOrderEventType::from('auto_approved'))->toBe(BnplOrderEventType::AutoApproved)
        ->and(BnplOrderEventType::from('category_set'))->toBe(BnplOrderEventType::CategorySet)
        ->and(BnplOrderEventType::from('rejected'))->toBe(BnplOrderEventType::Rejected);
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
