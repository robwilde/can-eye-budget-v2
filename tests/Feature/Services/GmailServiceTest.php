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
        'merchant_name' => null,
        'clean_description' => null,
        'description' => 'GENERAL STORE PURCHASE',
        'amount' => -1250,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-07-10',
        'category_id' => null,
    ], $overrides));
}

test('buildQuery searches from the payment processor for a PayPal Pay-in-4 line', function () {
    // Bank line names the processor, not the payee; the receipt is from PayPal.
    $transaction = gmailTransaction([
        'description' => 'VISA -PAYPAL *PYPL PAYIN4      1800073263   AU  680027 #8357',
        'amount' => -1376,
        'post_date' => '2026-07-06',
    ]);

    expect(app(GmailService::class)->buildQuery($transaction))
        ->toBe('from:paypal 13.76 after:2026/06/29 before:2026/07/14');
});

test('buildQuery drops the amount term when withAmount is false', function () {
    $transaction = gmailTransaction([
        'description' => 'VISA -PAYPAL *PYPL PAYIN4      1800073263   AU  680027 #8357',
        'amount' => -1376,
        'post_date' => '2026-07-06',
    ]);

    expect(app(GmailService::class)->buildQuery($transaction, withAmount: false))
        ->toBe('from:paypal after:2026/06/29 before:2026/07/14');
});

test('buildQuery detects Afterpay from the description', function () {
    $transaction = gmailTransaction([
        'description' => 'VISA -Afterpay                 afterpay.com AU  145377 #8357',
        'amount' => -3255,
        'post_date' => '2026-07-11',
    ]);

    expect(app(GmailService::class)->buildQuery($transaction))
        ->toBe('from:afterpay 32.55 after:2026/07/04 before:2026/07/19');
});

test('buildQuery uses the merchant name for a normal (non-processor) purchase', function () {
    $transaction = gmailTransaction([
        'merchant_name' => 'Woolworths',
        'description' => 'WOOLWORTHS 1234 SYDNEY',
    ]);

    expect(app(GmailService::class)->buildQuery($transaction))
        ->toBe('Woolworths 12.50 after:2026/07/03 before:2026/07/18');
});

test('buildQuery falls back to the description token when there is no merchant name', function () {
    $transaction = gmailTransaction([
        'merchant_name' => null,
        'clean_description' => null,
        'description' => 'BUNNINGS WAREHOUSE 456 ADELAIDE',
    ]);

    $token = app(CategoryRuleGenerator::class)->suggestMatchValue($transaction);
    $query = app(GmailService::class)->buildQuery($transaction);

    expect($token)->not->toBe('')
        ->and($query)->toStartWith($token.' ')
        ->and($query)->not->toStartWith('from:');
});

test('buildQuery strips double quotes from the merchant term', function () {
    $transaction = gmailTransaction([
        'merchant_name' => 'Big"W',
        'description' => 'BIG W STORE',
    ]);

    $query = app(GmailService::class)->buildQuery($transaction);

    expect($query)->not->toContain('"')
        ->and($query)->toStartWith('Big W ');
});

test('searchForTransaction throws when Gmail is not configured', function () {
    config(['imap.accounts.gmail.username' => null, 'imap.accounts.gmail.password' => null]);

    $service = app(GmailService::class);
    $transaction = gmailTransaction();

    expect($service->isConfigured())->toBeFalse();

    $service->searchForTransaction($transaction);
})->throws(GmailSearchException::class);

test('snippetFromBodies prefers the plain-text body', function () {
    expect(GmailService::snippetFromBodies('  Plain   text  body ', '<p>ignored</p>'))
        ->toBe('Plain text body');
});

test('snippetFromBodies strips style, script and head blocks from the HTML fallback', function () {
    $html = '<html><head><style>@font-face { font-family: SupremeLLTest; src: url("https://x"); }</style></head>'
        .'<body><script>var a = 1;</script><p>Your PayPal Pay in 4 payment went through. You paid $13.76 AUD.</p></body></html>';

    $snippet = GmailService::snippetFromBodies(null, $html);

    expect($snippet)->toContain('Your PayPal Pay in 4 payment went through')
        ->and($snippet)->not->toContain('font-face')
        ->and($snippet)->not->toContain('SupremeLL')
        ->and($snippet)->not->toContain('var a');
});

test('snippetFromBodies decodes entities and collapses whitespace', function () {
    expect(GmailService::snippetFromBodies(null, "<p>Ben &amp; Jerry&#39;s\n\n  order</p>"))
        ->toBe("Ben & Jerry's order");
});

test('snippetFromBodies returns null when both bodies are empty', function () {
    expect(GmailService::snippetFromBodies('', '   '))->toBeNull();
});
