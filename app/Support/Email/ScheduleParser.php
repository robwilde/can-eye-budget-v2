<?php

declare(strict_types=1);

namespace App\Support\Email;

use App\Contracts\ScheduleStrategy;
use App\DTOs\ParsedSchedule;
use App\DTOs\RawEmail;

/**
 * The BNPL schedule strategies, one per provider email format: the mailbox
 * scan runs each strategy's query, and parse() reads any single email.
 * Supporting a provider means adding its strategy to the binding in
 * AppServiceProvider.
 */
final readonly class ScheduleParser
{
    /**
     * @param  list<ScheduleStrategy>  $strategies
     */
    public function __construct(private array $strategies) {}

    /**
     * @return list<ScheduleStrategy>
     */
    public function strategies(): array
    {
        return $this->strategies;
    }

    /**
     * The schedule from the first strategy that recognises the email; null
     * when none does.
     */
    public function parse(RawEmail $email): ?ParsedSchedule
    {
        foreach ($this->strategies as $strategy) {
            $schedule = $strategy->parse($email);

            if ($schedule !== null) {
                return $schedule;
            }
        }

        return null;
    }
}
