<?php

declare(strict_types=1);

namespace App\Support\Email\Schedules;

use App\Contracts\ScheduleStrategy;
use App\DTOs\ParsedSchedule;
use App\DTOs\RawEmail;
use App\DTOs\ScheduleInstalment;
use App\Enums\BnplProvider;
use App\Support\Email\ReceiptParser;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\Log;

/**
 * PayPal "Pay in 4" instalment receipts ("Your PayPal Pay in 4 payment went
 * through"). Each receipt names its loan, the instalment it confirms
 * (amount + Posted on) and the instalments still to come, so the first
 * receipt seen for a loan describes the rest of the plan.
 */
final class PayPalReceiptStrategy implements ScheduleStrategy
{
    public function provider(): BnplProvider
    {
        return BnplProvider::Paypal;
    }

    /**
     * Gmail reads `after:` in its own timezone, so the window starts a day
     * early; re-reading a receipt is harmless (imports are idempotent).
     */
    public function query(CarbonImmutable $since): string
    {
        return 'from:paypal.com.au subject:(Pay in 4 payment went through) after:'.$since->subDay()->format('Y/m/d');
    }

    public function parse(RawEmail $email): ?ParsedSchedule
    {
        $receipt = ReceiptParser::parse($email->textBody, $email->htmlBody);

        if ($receipt === null
            || $receipt['loanReference'] === null
            || $receipt['total'] === null
            || $receipt['balance'] === null
            || $receipt['date'] === null
            || $receipt['seller'] === null) {
            return null;
        }

        $postedOn = $this->date($receipt['date'], $email);

        if ($postedOn === null) {
            return null;
        }

        $payment = new ScheduleInstalment($postedOn, $receipt['total']);
        $instalments = [$payment];

        foreach ($receipt['schedule'] as $entry) {
            $date = $this->date($entry['date'], $email);

            if ($date === null) {
                return null;
            }

            if ($date->greaterThan($postedOn)) {
                $instalments[] = new ScheduleInstalment($date, $entry['amount']);
            }
        }

        $upcoming = array_sum(array_map(static fn (ScheduleInstalment $instalment): int => $instalment->amount, array_slice($instalments, 1)));

        if ($upcoming !== $receipt['balance']) {
            Log::warning('PayPal receipt schedule does not match its balance', [
                'gmail_message_id' => $email->messageId,
                'balance' => $receipt['balance'],
                'scheduled' => $upcoming,
            ]);

            return null;
        }

        usort($instalments, static fn (ScheduleInstalment $a, ScheduleInstalment $b): int => $a->date <=> $b->date);

        return new ParsedSchedule(
            provider: BnplProvider::Paypal,
            retailer: $receipt['seller'],
            orderRef: $receipt['loanReference'],
            total: $receipt['total'] + $receipt['balance'],
            cardLast4: $receipt['last4'],
            instalments: $instalments,
            payment: $payment,
        );
    }

    /**
     * Null (and a warning) unless the value is a real calendar date: PHP
     * normalises an impossible day ("31 September") into the next month
     * instead of failing, so the parsed date must format back to the input.
     */
    private function date(string $value, RawEmail $email): ?CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!j F Y', $value, (string) config('app.timezone'));
        } catch (InvalidFormatException) {
            $date = null;
        }

        if ($date === null || mb_strtolower($date->format('j F Y')) !== mb_strtolower($value)) {
            Log::warning('PayPal receipt date unparseable', [
                'gmail_message_id' => $email->messageId,
                'date' => $value,
            ]);

            return null;
        }

        return $date;
    }
}
