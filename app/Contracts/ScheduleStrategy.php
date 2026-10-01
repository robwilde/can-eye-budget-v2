<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\ParsedSchedule;
use App\DTOs\RawEmail;
use App\Enums\BnplProvider;
use Carbon\CarbonImmutable;

/**
 * Reads one BNPL provider's schedule emails: which messages to fetch and how
 * to turn one of them into a repayment schedule.
 */
interface ScheduleStrategy
{
    public function provider(): BnplProvider;

    /**
     * Gmail X-GM-RAW query for this provider's schedule emails received on or
     * after $since. The IMAP library wraps the value in double quotes, so it
     * must never itself contain one.
     */
    public function query(CarbonImmutable $since): string;

    /**
     * Null when the email is not a complete schedule email of this provider.
     */
    public function parse(RawEmail $email): ?ParsedSchedule;
}
