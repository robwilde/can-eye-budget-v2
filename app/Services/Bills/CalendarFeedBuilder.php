<?php

declare(strict_types=1);

namespace App\Services\Bills;

use App\Casts\MoneyCast;
use App\Enums\OccurrenceStatus;
use App\Enums\TransactionDirection;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\Recurring\PlanAmountResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * An iCalendar (RFC 5545) feed of a user's open bills for the next ninety days: one all-day event per open
 * occurrence of an active debit plan, on its effective (snoozed) due date, carrying only the name and the amount.
 */
final readonly class CalendarFeedBuilder
{
    public const int DAYS = 90;

    public function __construct(private PlanAmountResolver $amounts) {}

    public function build(User $user, ?CarbonImmutable $today = null): string
    {
        $today ??= $user->today();
        $end = $today->addDays(self::DAYS);
        $stamp = CarbonImmutable::now('UTC')->format('Ymd\THis\Z');
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

        $occurrences = PlannedOccurrence::query()
            ->where('status', OccurrenceStatus::Scheduled->value)
            ->whereIn('planned_transaction_id', PlannedTransaction::query()->where('user_id', $user->id)->payable()->active()->where('direction', TransactionDirection::Debit->value)->select('id'))
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $unsnoozed): Builder => $unsnoozed->whereNull('snoozed_until')->whereDate('due_date', '>=', $today->toDateString())->whereDate('due_date', '<=', $end->toDateString()))
                ->orWhere(fn (Builder $snoozed): Builder => $snoozed->whereDate('snoozed_until', '>=', $today->toDateString())->whereDate('snoozed_until', '<=', $end->toDateString())))
            ->with('plannedTransaction')
            ->orderBy('id')
            ->get();

        $histories = $this->amounts->forPlans($occurrences->pluck('plannedTransaction')->unique('id')->values());

        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Can I Budget//Bills//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:'.$this->escape((string) __('Bills'))];

        foreach ($occurrences as $occurrence) {
            $plan = $occurrence->plannedTransaction;
            $due = $occurrence->effectiveDueDate();
            $amount = abs($occurrence->expected_amount ?? $histories[$plan->id] ?? $plan->amount);

            array_push($lines,
                'BEGIN:VEVENT',
                'UID:bill-'.$occurrence->id.'@'.$host,
                'DTSTAMP:'.$stamp,
                'DTSTART;VALUE=DATE:'.$due->format('Ymd'),
                'DTEND;VALUE=DATE:'.$due->addDay()->format('Ymd'),
                'SUMMARY:'.$this->escape($plan->description.' '.MoneyCast::format($amount)),
                'TRANSP:TRANSPARENT',
                'END:VEVENT',
            );
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'], $text);
    }

    /** Folds a content line at 75 octets without splitting a multibyte character. */
    private function fold(string $line): string
    {
        $parts = [];
        $current = '';

        foreach (mb_str_split($line) as $character) {
            $limit = $parts === [] ? 75 : 74;

            if (mb_strlen($current.$character, '8bit') > $limit) {
                $parts[] = $current;
                $current = '';
            }

            $current .= $character;
        }

        $parts[] = $current;

        return implode("\r\n ", $parts);
    }
}
