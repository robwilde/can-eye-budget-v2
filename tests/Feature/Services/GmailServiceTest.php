<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Exceptions\GmailSearchException;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategoryRuleGenerator;
use App\Services\GmailService;

function gmailTransaction(array $overrides = []): Transaction
{
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    return Transaction::factory()->for($user)->for($account)->manual()->create(array_merge([
        'merchant_name' => 'Afterpay',
        'clean_description' => null,
        'description' => 'AFTERPAY PURCHASE',
        'amount' => -1250,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-07-10',
        'category_id' => null,
    ], $overrides));
}

test('buildQuery emits merchant, amount and the ±7 day window', function () {
    $transaction = gmailTransaction();

    expect(app(GmailService::class)->buildQuery($transaction))
        ->toBe('Afterpay 12.50 after:2026/07/03 before:2026/07/18');
});

test('buildQuery drops the amount term when withAmount is false', function () {
    $transaction = gmailTransaction();

    expect(app(GmailService::class)->buildQuery($transaction, withAmount: false))
        ->toBe('Afterpay after:2026/07/03 before:2026/07/18');
});

test('buildQuery uses the suggested merchant token when there is no merchant name', function () {
    $transaction = gmailTransaction([
        'merchant_name' => null,
        'clean_description' => null,
        'description' => 'PAYPAL *NETFLIX SYDNEY',
    ]);

    $token = app(CategoryRuleGenerator::class)->suggestMatchValue($transaction);

    expect($token)->not->toBe('')
        ->and(app(GmailService::class)->buildQuery($transaction))
        ->toStartWith($token.' ');
});

test('buildQuery strips double quotes from the merchant term', function () {
    $transaction = gmailTransaction(['merchant_name' => 'Pay"Pal']);

    $query = app(GmailService::class)->buildQuery($transaction);

    expect($query)->not->toContain('"')
        ->and($query)->toStartWith('Pay Pal ');
});

test('searchForTransaction throws when Gmail is not configured', function () {
    config(['imap.accounts.gmail.username' => null, 'imap.accounts.gmail.password' => null]);

    $service = app(GmailService::class);
    $transaction = gmailTransaction();

    expect($service->isConfigured())->toBeFalse();

    $service->searchForTransaction($transaction);
})->throws(GmailSearchException::class);
