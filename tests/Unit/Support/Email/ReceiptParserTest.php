<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Support\Email\ReceiptParser;

$afterpayHtml = <<<'HTML'
<html><head><style>.x{color:red}</style></head><body>
<h1>Payment confirmation</h1>
<table>
  <tr><td>Total amount paid</td><td>$112.79</td></tr>
  <tr><td>Payment date</td><td>Fri, 3 July 2026</td></tr>
</table>
<h2>Repayments</h2>
<table><tr><td>Payment method</td><td>Visa</td><td>&bull;&bull;&bull;&bull; 8357</td></tr></table>
<table>
  <tr><td>Petbarn</td><td>Order #900438021</td><td>4 of 4</td><td>$21.24</td></tr>
  <tr><td>Petbarn</td><td>Order #900438021</td><td>4 of 4</td><td>$21.24</td></tr>
  <tr><td>Addicted To Audio</td><td>Order #917982502</td><td>2 of 4</td><td>$34.75</td></tr>
  <tr><td>Addicted To Audio</td><td>Order #917982502</td><td>2 of 4</td><td>$34.75</td></tr>
</table>
</body></html>
HTML;

$paypalHtml = <<<'HTML'
<html><head><style>.x{color:red}</style></head><body>
<h1>You sent a payment</h1>
<p>You made a $16.01 AUD payment for your Pay in 4 plan. The payment was
charged to the Credit Card ending in x-8357 on 25 September 2025.</p>
<h2>Here are the details</h2>
<table>
  <tr><td>Payment amount</td><td>$16.01 AUD</td></tr>
  <tr><td>Payment type</td><td>Plan payment</td></tr>
  <tr><td>Payment method</td><td>BEYOND BANK AUSTRALIA LIMITED<br>Credit Card<br>x-8357</td></tr>
  <tr><td>Posted on</td><td>25 September 2025</td></tr>
  <tr><td>Seller</td><td>ONLINE STORE</td></tr>
  <tr><td>Current balance</td><td>$0.00 AUD</td></tr>
  <tr><td>Loan reference number</td><td>eacfa072-30dc-40eb-a93d-acc70b06d4d2</td></tr>
</table>
<p><a href="https://www.paypal.com/myaccount/ppcredit/plans/eacfa072-30dc-40eb-a93d-acc70b06d4d2">To make early payments, or to review your PayPal Pay in 4 Contract, log in to your PayPal account.</a></p>
</body></html>
HTML;

test('parses total, date and payment method from an Afterpay receipt', function () use ($afterpayHtml) {
    $receipt = ReceiptParser::parse(null, $afterpayHtml);

    expect($receipt)->not->toBeNull()
        ->and($receipt['total'])->toBe(11279)
        ->and($receipt['date'])->toBe('Fri, 3 July 2026')
        ->and($receipt['method'])->toBe('Visa')
        ->and($receipt['last4'])->toBe('8357');
});

test('deduplicates responsive line-item rows and keeps distinct merchants', function () use ($afterpayHtml) {
    $receipt = ReceiptParser::parse(null, $afterpayHtml);

    expect($receipt['items'])->toHaveCount(2)
        ->and($receipt['items'][0])->toBe([
            'merchant' => 'Petbarn',
            'reference' => '900438021',
            'installment' => '4 of 4',
            'amount' => 2124,
        ])
        ->and($receipt['items'][1]['merchant'])->toBe('Addicted To Audio')
        ->and($receipt['items'][1]['amount'])->toBe(3475);
});

test('line item amounts sum to the transaction total for a full receipt', function () {
    $html = <<<'HTML'
    <body>Payment confirmation Total amount paid $112.79 Payment date Fri, 3 July 2026
    Repayments Payment method Visa &bull;&bull;&bull;&bull; 8357
    Petbarn Order #900438021 4 of 4 $21.24
    Addicted To Audio Order #917982502 2 of 4 $34.75
    Petbarn Order #919235542 2 of 4 $35.80
    Petbarn Order #927522361 1 of 4 $21.00</body>
    HTML;

    $receipt = ReceiptParser::parse(null, $html);

    expect($receipt['items'])->toHaveCount(4)
        ->and(collect($receipt['items'])->sum('amount'))->toBe($receipt['total']);
});

test('prefers the plain-text body when present', function () {
    $text = 'Total amount paid $50.00 Repayments Payment method Visa •••• 1234 Store A Order #1 1 of 4 $50.00';

    $receipt = ReceiptParser::parse($text, '<body>ignored</body>');

    expect($receipt['total'])->toBe(5000)
        ->and($receipt['items'])->toHaveCount(1)
        ->and($receipt['items'][0]['merchant'])->toBe('Store A');
});

test('returns null for a non-receipt email', function () {
    expect(ReceiptParser::parse('Hi there, just checking in about your order.', null))->toBeNull();
});

test('returns null for empty bodies', function () {
    expect(ReceiptParser::parse(null, null))->toBeNull()
        ->and(ReceiptParser::parse('', ''))->toBeNull();
});

test('parses a PayPal Pay-in-4 plan payment receipt', function () use ($paypalHtml) {
    $receipt = ReceiptParser::parse(null, $paypalHtml);

    expect($receipt)->not->toBeNull()
        ->and($receipt['total'])->toBe(1601)
        ->and($receipt['type'])->toBe('Plan payment')
        ->and($receipt['method'])->toBe('BEYOND BANK AUSTRALIA LIMITED Credit Card')
        ->and($receipt['last4'])->toBe('8357')
        ->and($receipt['date'])->toBe('25 September 2025')
        ->and($receipt['seller'])->toBe('ONLINE STORE')
        ->and($receipt['balance'])->toBe(0)
        ->and($receipt['loanReference'])->toBe('eacfa072-30dc-40eb-a93d-acc70b06d4d2')
        ->and($receipt['items'])->toBe([]);
});

test('falls back to the plan link for the loan reference', function () use ($paypalHtml) {
    $html = preg_replace('/<tr><td>Loan reference number.*?<\/tr>/s', '', $paypalHtml);

    $receipt = ReceiptParser::parse(null, $html);

    expect($receipt)->not->toBeNull()
        ->and($receipt['loanReference'])->toBe('eacfa072-30dc-40eb-a93d-acc70b06d4d2');
});

test('Afterpay receipts leave the PayPal-only fields null', function () use ($afterpayHtml) {
    $receipt = ReceiptParser::parse(null, $afterpayHtml);

    expect($receipt)->not->toBeNull()
        ->and($receipt['type'])->toBeNull()
        ->and($receipt['seller'])->toBeNull()
        ->and($receipt['balance'])->toBeNull()
        ->and($receipt['loanReference'])->toBeNull();
});
