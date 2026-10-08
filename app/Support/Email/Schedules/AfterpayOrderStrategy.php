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
 * Afterpay "Thank you for your Afterpay order" confirmations: the whole future
 * schedule, with no payment taken yet. The weekday printed beside each due
 * date is what fixes the date format as day/month, so a date that disagrees
 * with its weekday is rejected rather than guessed.
 */
final class AfterpayOrderStrategy implements ScheduleStrategy
{
    public function provider(): BnplProvider
    {
        return BnplProvider::Afterpay;
    }

    /**
     * Gmail reads `after:` in its own timezone, so the window starts a day
     * early; re-reading an order is harmless (imports are idempotent).
     */
    public function query(CarbonImmutable $since): string
    {
        return 'from:afterpay.com subject:(Thank you for your Afterpay order) after:'.$since->subDay()->format('Y/m/d');
    }

    public function parse(RawEmail $email): ?ParsedSchedule
    {
        $text = ReceiptParser::flatten($email->textBody, $email->htmlBody);

        if (preg_match('/Total amount paid/i', $text) === 1) {
            return null;
        }

        if (preg_match('/Afterpay order number:\s*(\d+)/i', $text, $order) !== 1
            || preg_match('/Retailer:\s*(.+?)\s+Afterpay order number:/i', $text, $retailer) !== 1
            || preg_match('/\bTotal\s+\$([\d,]+\.\d{2})/i', $text, $total) !== 1) {
            return null;
        }

        $rows = [];
        preg_match_all('/\$([\d,]+\.\d{2})\s+DUE DATE:\s*([A-Za-z]{3}),\s*(\d{2}\/\d{2}\/\d{4})/i', $text, $rows, PREG_SET_ORDER);

        if ($rows === []) {
            return null;
        }

        $instalments = [];

        foreach ($rows as $row) {
            $date = $this->date($row[2], $row[3], $email);

            if ($date === null) {
                return null;
            }

            $instalments[] = new ScheduleInstalment($date, $this->cents($row[1]));
        }

        usort($instalments, static fn (ScheduleInstalment $a, ScheduleInstalment $b): int => $a->date <=> $b->date);

        $totalCents = $this->cents($total[1]);
        $scheduled = array_sum(array_map(static fn (ScheduleInstalment $instalment): int => $instalment->amount, $instalments));

        if ($scheduled !== $totalCents) {
            Log::warning('Afterpay order total mismatch', [
                'gmail_message_id' => $email->messageId,
                'total' => $totalCents,
                'instalments' => $scheduled,
            ]);

            return null;
        }

        $card = preg_match('/card ending in \*+(\d{4})/i', $text, $last4) === 1 ? $last4[1] : null;

        return new ParsedSchedule(
            provider: BnplProvider::Afterpay,
            retailer: mb_trim($retailer[1]),
            orderRef: $order[1],
            total: $totalCents,
            cardLast4: $card,
            instalments: $instalments,
            payment: null,
        );
    }

    private function cents(string $amount): int
    {
        return (int) str_replace([',', '.'], '', $amount);
    }

    /**
     * Null (and a warning) unless the value is a real day/month/year date
     * whose weekday matches: PHP rolls an impossible day ("31/02") into the
     * next month, and a month/day reading would land on a different weekday.
     */
    private function date(string $weekday, string $dmy, RawEmail $email): ?CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!d/m/Y', $dmy, (string) config('app.timezone'));
        } catch (InvalidFormatException) {
            $date = null;
        }

        if ($date === null
            || $date->format('d/m/Y') !== $dmy
            || strcasecmp($date->format('D'), $weekday) !== 0) {
            Log::warning('Afterpay order date unparseable', [
                'gmail_message_id' => $email->messageId,
                'date' => "$weekday, $dmy",
            ]);

            return null;
        }

        return $date;
    }
}
