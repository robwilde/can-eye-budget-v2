<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\ScheduleStrategy;
use App\DTOs\ParsedSchedule;
use App\DTOs\RawEmail;
use App\DTOs\ScheduleInstalment;
use App\Enums\BnplProvider;
use App\Support\Email\ScheduleParser;
use Carbon\CarbonImmutable;

/**
 * A strategy that recognises every email as $retailer's schedule, or none
 * when $retailer is null, and counts how often it is asked.
 */
function fixedScheduleStrategy(?string $retailer): ScheduleStrategy
{
    return new class($retailer) implements ScheduleStrategy
    {
        public int $calls = 0;

        public function __construct(private readonly ?string $retailer) {}

        public function provider(): BnplProvider
        {
            return BnplProvider::Paypal;
        }

        public function query(CarbonImmutable $since): string
        {
            return '';
        }

        public function parse(RawEmail $email): ?ParsedSchedule
        {
            $this->calls++;

            if ($this->retailer === null) {
                return null;
            }

            $instalment = new ScheduleInstalment(CarbonImmutable::parse('2026-07-21'), 5025);

            return new ParsedSchedule(BnplProvider::Paypal, $this->retailer, 'ref', 5025, null, [$instalment], $instalment);
        }
    };
}

function anyRawEmail(): RawEmail
{
    return new RawEmail('message@mail.test', 'Subject', null, 'sender@mail.test', null, 'body', null);
}

test('the first strategy that recognises the email wins and later ones are not asked', function () {
    $last = fixedScheduleStrategy('Third');
    $parser = new ScheduleParser([fixedScheduleStrategy(null), fixedScheduleStrategy('Second'), $last]);

    expect($parser->parse(anyRawEmail())?->retailer)->toBe('Second')
        ->and($last->calls)->toBe(0);
});

test('an email no strategy recognises parses to null', function () {
    $parser = new ScheduleParser([fixedScheduleStrategy(null), fixedScheduleStrategy(null)]);

    expect($parser->parse(anyRawEmail()))->toBeNull();
});
