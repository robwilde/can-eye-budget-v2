<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\RawEmail;
use App\DTOs\ScheduleInstalment;
use App\Enums\BnplProvider;
use App\Enums\RecurrenceFrequency;
use App\Support\Email\Schedules\AfterpayOrderStrategy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

test('an order confirmation yields the retailer, reference, total, card and four fortnightly instalments', function () {
    $schedule = (new AfterpayOrderStrategy)->parse(afterpayOrderEmail());

    expect($schedule)->not->toBeNull()
        ->and($schedule->provider)->toBe(BnplProvider::Afterpay)
        ->and($schedule->retailer)->toBe('Petbarn')
        ->and($schedule->orderRef)->toBe('953186001')
        ->and($schedule->total)->toBe(7445)
        ->and($schedule->cardLast4)->toBe('8357')
        ->and($schedule->payment)->toBeNull()
        ->and(array_map(
            static fn (ScheduleInstalment $instalment): array => [$instalment->date->toDateString(), $instalment->amount],
            $schedule->instalments,
        ))->toBe([
            ['2026-08-07', 1861],
            ['2026-08-21', 1861],
            ['2026-09-04', 1861],
            ['2026-09-18', 1862],
        ])
        ->and($schedule->frequency())->toBe(RecurrenceFrequency::Every2Weeks)
        ->and($schedule->instalmentAmount())->toBe(1861);
});

test('an Afterpay payment receipt is not an order schedule', function () {
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

    expect((new AfterpayOrderStrategy)->parse($email))->toBeNull();
});

test('a PayPal receipt is not an Afterpay schedule', function () {
    expect((new AfterpayOrderStrategy)->parse(payPalReceiptEmail()))->toBeNull();
});

test('a due date whose weekday contradicts day-month order is rejected', function () {
    Log::spy();

    $email = afterpayOrderEmail(['Fri, 07/08/2026' => 'Wed, 07/08/2026']);

    expect((new AfterpayOrderStrategy)->parse($email))->toBeNull();

    Log::shouldHaveReceived('warning')->once();
});

test('an impossible calendar date is rejected', function () {
    $email = afterpayOrderEmail(['Fri, 21/08/2026' => 'Mon, 31/02/2026']);

    expect((new AfterpayOrderStrategy)->parse($email))->toBeNull();
});

test('unevenly spaced due dates keep the schedule but derive no cadence', function () {
    $email = afterpayOrderEmail(['Fri, 21/08/2026' => 'Tue, 18/08/2026']);

    $schedule = (new AfterpayOrderStrategy)->parse($email);

    expect($schedule)->not->toBeNull()
        ->and($schedule->frequency())->toBeNull();
});

test('instalments that do not add up to the total are rejected', function () {
    $email = afterpayOrderEmail(['$74.45' => '$75.45']);

    expect((new AfterpayOrderStrategy)->parse($email))->toBeNull();
});

test('the query starts a day before the scan window', function () {
    expect((new AfterpayOrderStrategy)->query(CarbonImmutable::parse('2026-10-02')))
        ->toBe('from:afterpay.com subject:(Thank you for your Afterpay order) after:2026/10/01');
});
