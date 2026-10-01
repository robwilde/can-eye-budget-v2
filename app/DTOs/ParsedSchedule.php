<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\BnplProvider;
use App\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Spatie\LaravelData\Dto;

/**
 * A BNPL repayment schedule as read from one email. Everything here describes
 * the plan from that email onward: a receipt for the second instalment yields
 * three instalments and a total of the remaining value, not the purchase price.
 */
final class ParsedSchedule extends Dto
{
    /**
     * Day gaps that map onto a recurrence the planned-transaction engine can
     * replay exactly.
     *
     * @var array<int, RecurrenceFrequency>
     */
    private const array CADENCES = [
        7 => RecurrenceFrequency::EveryWeek,
        14 => RecurrenceFrequency::Every2Weeks,
        21 => RecurrenceFrequency::Every3Weeks,
        28 => RecurrenceFrequency::Every4Weeks,
    ];

    /**
     * @param  string  $orderRef  the provider's plan identifier (PayPal: loan reference uuid)
     * @param  int  $total  cents; the total of the instalments known from this email onward
     * @param  list<ScheduleInstalment>  $instalments  chronological; includes the instalment this email confirms
     * @param  ScheduleInstalment|null  $payment  the instalment this receipt confirms as paid; null for order-confirmation emails
     */
    public function __construct(
        public readonly BnplProvider $provider,
        public readonly string $retailer,
        public readonly string $orderRef,
        public readonly int $total,
        public readonly ?string $cardLast4,
        public readonly array $instalments,
        public readonly ?ScheduleInstalment $payment,
    ) {
        throw_if($instalments === [], InvalidArgumentException::class, 'A parsed schedule needs at least one instalment.');
    }

    /**
     * One instalment never repeats; otherwise every gap between consecutive
     * instalments must be the same whole number of weeks (1–4). Anything else
     * is null: no recurrence would replay the schedule faithfully.
     */
    public function frequency(): ?RecurrenceFrequency
    {
        if (count($this->instalments) === 1) {
            return RecurrenceFrequency::DontRepeat;
        }

        $gaps = [];

        for ($i = 1, $n = count($this->instalments); $i < $n; $i++) {
            $gaps[] = (int) round($this->instalments[$i - 1]->date->diffInDays($this->instalments[$i]->date));
        }

        $gaps = array_values(array_unique($gaps));

        if (count($gaps) !== 1) {
            return null;
        }

        return self::CADENCES[$gaps[0]] ?? null;
    }

    /**
     * The amount most instalments share (a provider puts the rounding cent on
     * one instalment); on a tie, the smallest amount.
     */
    public function instalmentAmount(): int
    {
        $counts = array_count_values(array_map(
            static fn (ScheduleInstalment $instalment): int => $instalment->amount,
            $this->instalments,
        ));
        $highest = max($counts);

        return min(array_keys(array_filter($counts, static fn (int $count): bool => $count === $highest)));
    }

    public function firstDueDate(): CarbonImmutable
    {
        return $this->instalments[0]->date;
    }

    public function lastDueDate(): CarbonImmutable
    {
        return $this->instalments[count($this->instalments) - 1]->date;
    }

    /**
     * JSON-safe shape stored in bnpl_orders.parsed_payload.
     *
     * @return array{provider: string, retailer: string, orderRef: string, total: int, cardLast4: string|null, instalments: list<array{date: string, amount: int}>, payment: array{date: string, amount: int}|null}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider->value,
            'retailer' => $this->retailer,
            'orderRef' => $this->orderRef,
            'total' => $this->total,
            'cardLast4' => $this->cardLast4,
            'instalments' => array_map(
                static fn (ScheduleInstalment $instalment): array => $instalment->toArray(),
                $this->instalments,
            ),
            'payment' => $this->payment?->toArray(),
        ];
    }
}
