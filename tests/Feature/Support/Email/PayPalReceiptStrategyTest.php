<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\RawEmail;
use App\DTOs\ScheduleInstalment;
use App\Enums\BnplProvider;
use App\Enums\RecurrenceFrequency;
use App\Support\Email\Schedules\PayPalReceiptStrategy;
use Carbon\CarbonImmutable;

/**
 * @return list<array{0: string, 1: int}>
 */
function instalmentRows(array $instalments): array
{
    return array_map(
        static fn (ScheduleInstalment $instalment): array => [$instalment->date->toDateString(), $instalment->amount],
        $instalments,
    );
}

test('the first receipt of a loan describes the whole remaining plan', function () {
    $schedule = (new PayPalReceiptStrategy)->parse(payPalReceiptEmail());

    expect($schedule)->not->toBeNull()
        ->and($schedule->provider)->toBe(BnplProvider::Paypal)
        ->and($schedule->orderRef)->toBe('eacfa072-30dc-40eb-a93d-acc70b06d4d2')
        ->and($schedule->retailer)->toBe('Umart Online')
        ->and($schedule->cardLast4)->toBe('8357')
        ->and([$schedule->payment->date->toDateString(), $schedule->payment->amount])->toBe(['2026-07-21', 5025])
        ->and(instalmentRows($schedule->instalments))->toBe([
            ['2026-07-21', 5025],
            ['2026-08-04', 5025],
            ['2026-08-18', 5025],
            ['2026-09-01', 5024],
        ])
        ->and($schedule->total)->toBe(20099)
        ->and($schedule->instalmentAmount())->toBe(5025)
        ->and($schedule->frequency())->toBe(RecurrenceFrequency::Every2Weeks)
        ->and($schedule->firstDueDate()->toDateString())->toBe('2026-07-21')
        ->and($schedule->lastDueDate()->toDateString())->toBe('2026-09-01');
});

test('the final receipt is a single instalment that does not repeat', function () {
    $schedule = (new PayPalReceiptStrategy)->parse(payPalReceiptEmail('last', 'paypal-receipt-last@mail.test'));

    expect($schedule)->not->toBeNull()
        ->and(instalmentRows($schedule->instalments))->toBe([['2026-09-01', 5024]])
        ->and($schedule->frequency())->toBe(RecurrenceFrequency::DontRepeat)
        ->and($schedule->total)->toBe(5024);
});

test('uneven gaps between instalments have no supported cadence', function () {
    $schedule = (new PayPalReceiptStrategy)->parse(payPalReceiptEmail(replace: ['18 August 2026' => '19 August 2026']));

    expect($schedule)->not->toBeNull()
        ->and($schedule->frequency())->toBeNull();
});

test('an unparseable schedule date rejects the receipt', function () {
    $email = payPalReceiptEmail(replace: ['on 4 August 2026' => 'on 4 Augustus 2026']);

    expect((new PayPalReceiptStrategy)->parse($email))->toBeNull();
});

test('an impossible calendar date rejects the receipt instead of rolling into the next month', function () {
    $email = payPalReceiptEmail(replace: ['on 1 September 2026' => 'on 31 September 2026']);

    expect((new PayPalReceiptStrategy)->parse($email))->toBeNull();
});

test('a receipt without a current balance is rejected rather than undercounting the total', function () {
    $email = payPalReceiptEmail(replace: ['$150.74&nbsp;AUD' => '']);

    expect((new PayPalReceiptStrategy)->parse($email))->toBeNull();
});

test('a receipt whose schedule paragraph is missing is rejected while a balance is owing', function () {
    $email = payPalReceiptEmail(replace: [
        "As a reminder, here's your upcoming payment schedule: $50.25&nbsp;AUD on 4 August 2026 $50.25&nbsp;AUD on 18 August 2026 $50.24&nbsp;AUD on 1 September 2026" => '',
    ]);

    expect((new PayPalReceiptStrategy)->parse($email))->toBeNull();
});

test('a schedule entry dated on the posted date is not counted twice', function () {
    $email = payPalReceiptEmail(replace: ['on 4 August 2026' => 'on 21 July 2026 $50.25&nbsp;AUD on 4 August 2026']);

    $schedule = (new PayPalReceiptStrategy)->parse($email);

    expect($schedule)->not->toBeNull()
        ->and(instalmentRows($schedule->instalments))->toBe([
            ['2026-07-21', 5025],
            ['2026-08-04', 5025],
            ['2026-08-18', 5025],
            ['2026-09-01', 5024],
        ]);
});

test('a schedule that does not add up to the balance is rejected', function () {
    $email = payPalReceiptEmail(replace: ['$50.24&nbsp;AUD on 1 September 2026' => '$40.24&nbsp;AUD on 1 September 2026']);

    expect((new PayPalReceiptStrategy)->parse($email))->toBeNull();
});

test('a "will be charged on" schedule wording is still read', function () {
    $email = payPalReceiptEmail(replace: [
        '$50.25&nbsp;AUD on 4 August 2026' => '$50.25&nbsp;AUD will be charged on 4 August 2026',
        '$50.25&nbsp;AUD on 18 August 2026' => '$50.25&nbsp;AUD will be charged on 18 August 2026',
        '$50.24&nbsp;AUD on 1 September 2026' => '$50.24&nbsp;AUD will be charged on 1 September 2026',
    ]);

    $schedule = (new PayPalReceiptStrategy)->parse($email);

    expect($schedule)->not->toBeNull()
        ->and(instalmentRows($schedule->instalments))->toBe([
            ['2026-07-21', 5025],
            ['2026-08-04', 5025],
            ['2026-08-18', 5025],
            ['2026-09-01', 5024],
        ]);
});

test('an Afterpay receipt is not a PayPal schedule', function () {
    $html = <<<'HTML'
    <body><h1>Payment confirmation</h1>
    <table><tr><td>Total amount paid</td><td>$112.79</td></tr><tr><td>Payment date</td><td>Fri, 3 July 2026</td></tr></table>
    <h2>Repayments</h2><table><tr><td>Payment method</td><td>Visa</td><td>&bull;&bull;&bull;&bull; 8357</td></tr></table>
    <table><tr><td>Petbarn</td><td>Order #900438021</td><td>4 of 4</td><td>$21.24</td></tr></table></body>
    HTML;

    $email = new RawEmail(
        messageId: 'afterpay@mail.test',
        subject: 'Payment confirmation',
        fromName: 'Afterpay',
        fromAddress: 'noreply@afterpay.com',
        date: CarbonImmutable::parse('2026-07-03'),
        textBody: null,
        htmlBody: $html,
    );

    expect((new PayPalReceiptStrategy)->parse($email))->toBeNull();
});

test('the query starts a day before the scan window', function () {
    expect((new PayPalReceiptStrategy)->query(CarbonImmutable::parse('2026-10-02')))
        ->toBe('from:paypal.com.au subject:(Pay in 4 payment went through) after:2026/10/01');
});
