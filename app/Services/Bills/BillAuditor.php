<?php

declare(strict_types=1);

namespace App\Services\Bills;

use App\Casts\MoneyCast;
use App\DTOs\BillAuditReport;
use App\DTOs\BillAuditSection;
use App\Enums\OccurrenceStatus;
use App\Enums\PlannedTransactionStatus;
use App\Enums\SuggestionType;
use App\Models\AnalysisSuggestion;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\PlannedTransactionPriceChange;
use App\Models\User;
use App\Services\Recurring\RecurringGate;
use App\Services\Recurring\RecurringSuggestionWriter;
use App\Services\Recurring\RecurringTransactionDetector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * A read-only review of a user's bills for problems: possible duplicates, recent price changes, trials and renewals
 * coming up, bills overdue by a whole cycle, paused bills that still have open occurrences, detections waiting to
 * be confirmed and recurring charges that were never declared. Nothing is written: fixes go through the editors.
 */
final readonly class BillAuditor
{
    public const int SECTION_LIMIT = 20;

    public const int MIN_LOOKBACK_MONTHS = 1;

    public const int MAX_LOOKBACK_MONTHS = 24;

    public const int DEFAULT_LOOKBACK_MONTHS = 12;

    public const int NOTICE_DAYS = 30;

    public function __construct(private RecurringTransactionDetector $detector, private RecurringSuggestionWriter $writer) {}

    /** @throws ValidationException */
    public static function assertLookback(int $lookbackMonths): void
    {
        if ($lookbackMonths < self::MIN_LOOKBACK_MONTHS || $lookbackMonths > self::MAX_LOOKBACK_MONTHS) {
            throw ValidationException::withMessages(['lookback' => __('Look back between :min and :max months.', ['min' => self::MIN_LOOKBACK_MONTHS, 'max' => self::MAX_LOOKBACK_MONTHS])]);
        }
    }

    /**
     * @throws ValidationException when the look-back is outside one to twenty-four months (it is rejected, not clamped)
     */
    public function audit(User $user, int $lookbackMonths = self::DEFAULT_LOOKBACK_MONTHS, ?CarbonImmutable $today = null): BillAuditReport
    {
        self::assertLookback($lookbackMonths);

        if (! RecurringGate::enabled($user)) {
            return BillAuditReport::disabled($lookbackMonths);
        }

        $today ??= $user->today();
        $plans = PlannedTransaction::query()->where('user_id', $user->id)->visible()->orderBy('id')->get();
        $active = $plans->filter(static fn (PlannedTransaction $plan): bool => $plan->isActive())->values();

        return new BillAuditReport(true, $lookbackMonths, [
            'duplicates' => $this->duplicates($active, $today),
            'priceChanges' => $this->priceChanges($user, $lookbackMonths, $today),
            'trials' => $this->dated($active, 'trial_ends_on', $today, __('Trial ends')),
            'renewals' => $this->dated($active, 'renewal_on', $today, __('Renews')),
            'overdue' => $this->overdue($active, $today),
            'pausedWithOpen' => $this->pausedWithOpen($plans),
            'pendingDetections' => $this->pendingDetections($user),
            'undeclared' => $this->undeclared($user),
        ]);
    }

    public function priceChanges(User $user, int $months, ?CarbonImmutable $today = null): BillAuditSection
    {
        self::assertLookback($months);
        $today ??= $user->today();

        return $this->section(PlannedTransactionPriceChange::query()
            ->whereIn('planned_transaction_id', PlannedTransaction::query()->where('user_id', $user->id)->visible()->select('id'))
            ->whereDate('effective_on', '>=', $today->subMonthsNoOverflow($months)->toDateString())
            ->with('plannedTransaction:id,description')
            ->orderByDesc('effective_on')
            ->get()
            ->map(static fn (PlannedTransactionPriceChange $change): array => ['planId' => $change->planned_transaction_id, 'label' => $change->plannedTransaction->description, 'detail' => $change->effective_on->format('Y-m-d').': '.MoneyCast::format(abs($change->previous_amount)).' → '.MoneyCast::format(abs($change->new_amount))]));
    }

    /** @param  Collection<int, PlannedTransaction>  $active */
    private function duplicates(Collection $active, CarbonImmutable $today): BillAuditSection
    {
        $groups = $active
            ->filter(static fn (PlannedTransaction $plan): bool => $plan->transfer_to_account_id === null)
            ->groupBy(fn (PlannedTransaction $plan): string => $this->normalise($plan->description).'|'.$plan->direction->value.'|'.abs($plan->amount).'|'.($plan->nextOccurrenceOnOrAfter($today) ?? $plan->start_date)->toDateString())
            ->filter(static fn (Collection $group): bool => $group->count() > 1);

        return $this->section($groups->flatten(1)->map(static fn (PlannedTransaction $plan): array => ['planId' => $plan->id, 'label' => $plan->description, 'detail' => (string) __('Same name, amount and day as another bill')])->values());
    }

    /** @param  Collection<int, PlannedTransaction>  $active */
    private function dated(Collection $active, string $column, CarbonImmutable $today, string $verb): BillAuditSection
    {
        $until = $today->addDays(self::NOTICE_DAYS);

        return $this->section($active
            ->filter(static fn (PlannedTransaction $plan): bool => $plan->{$column} !== null && $plan->{$column}->greaterThanOrEqualTo($today) && $plan->{$column}->lessThanOrEqualTo($until))
            ->sortBy(static fn (PlannedTransaction $plan): string => $plan->{$column}->format('Y-m-d'))
            ->map(static fn (PlannedTransaction $plan): array => ['planId' => $plan->id, 'label' => $plan->description, 'detail' => $verb.' '.$plan->{$column}->format('Y-m-d')]));
    }

    /**
     * Open occurrences more than one of the plan's own cycles past their effective due date. A weekly bill is
     * overdue after a week, a monthly one after a month.
     *
     * @param  Collection<int, PlannedTransaction>  $active
     */
    private function overdue(Collection $active, CarbonImmutable $today): BillAuditSection
    {
        $byId = $active->keyBy('id');

        $rows = PlannedOccurrence::query()
            ->where('status', OccurrenceStatus::Scheduled->value)
            ->whereIn('planned_transaction_id', $byId->keys()->all())
            ->whereDate('due_date', '<', $today->toDateString())
            ->withSum('payments', 'amount')
            ->orderBy('due_date')
            ->get()
            ->filter(static function (PlannedOccurrence $occurrence) use ($byId, $today): bool {
                $plan = $byId[$occurrence->planned_transaction_id];

                return CarbonImmutable::instance($occurrence->effectiveDueDate())->addDays($plan->frequency->cycleDays($plan->recurrence_interval))->lessThanOrEqualTo($today)
                    && (int) $occurrence->payments_sum_amount < abs($occurrence->expected_amount ?? $plan->amount);
            });

        return $this->section($rows->unique('planned_transaction_id')->map(static fn (PlannedOccurrence $occurrence): array => ['planId' => $occurrence->planned_transaction_id, 'label' => $byId[$occurrence->planned_transaction_id]->description, 'detail' => (string) __('Due :date', ['date' => $occurrence->effectiveDueDate()->format('Y-m-d')])]));
    }

    /** @param  Collection<int, PlannedTransaction>  $plans */
    private function pausedWithOpen(Collection $plans): BillAuditSection
    {
        $paused = $plans->filter(static fn (PlannedTransaction $plan): bool => $plan->status === PlannedTransactionStatus::Inactive)->keyBy('id');

        if ($paused->isEmpty()) {
            return $this->section(collect());
        }

        $open = PlannedOccurrence::query()
            ->where('status', OccurrenceStatus::Scheduled->value)
            ->whereIn('planned_transaction_id', $paused->keys()->all())
            ->selectRaw('planned_transaction_id, COUNT(*) as open_count')
            ->groupBy('planned_transaction_id')
            ->orderBy('planned_transaction_id')
            ->pluck('open_count', 'planned_transaction_id');

        return $this->section($open->map(static fn (int|string $count, int $planId): array => ['planId' => $planId, 'label' => $paused[$planId]->description, 'detail' => trans_choice(':count open occurrence|:count open occurrences', (int) $count, ['count' => (int) $count])])->values());
    }

    private function pendingDetections(User $user): BillAuditSection
    {
        return $this->section(AnalysisSuggestion::query()
            ->where('user_id', $user->id)
            ->ofType(SuggestionType::RecurringTransaction)
            ->pending()
            ->orderBy('id')
            ->get()
            ->map(static fn (AnalysisSuggestion $suggestion): array => ['planId' => null, 'label' => (string) ($suggestion->payload['clean_description'] ?? $suggestion->payload['description'] ?? ''), 'detail' => (string) __('Waiting for you to confirm')]));
    }

    private function undeclared(User $user): BillAuditSection
    {
        $pending = AnalysisSuggestion::query()
            ->where('user_id', $user->id)
            ->ofType(SuggestionType::RecurringTransaction)
            ->pending()
            ->get(['payload'])
            ->map(static fn (AnalysisSuggestion $suggestion): string => ($suggestion->payload['description'] ?? '').'|'.($suggestion->payload['account_id'] ?? ''))
            ->flip();

        return $this->section($this->detector->detect($user)
            ->filter(fn ($candidate): bool => ! $pending->has($candidate->description.'|'.$candidate->accountId) && $this->writer->skipReason($user, $candidate) === null)
            ->map(static fn ($candidate): array => ['planId' => null, 'label' => $candidate->cleanDescription, 'detail' => $candidate->frequency->label()])
            ->values());
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $items
     */
    private function section(iterable $items): BillAuditSection
    {
        $items = collect($items);

        $rows = $items->take(self::SECTION_LIMIT)->map(static fn (array $item): array => [
            'planId' => is_int($item['planId']) ? $item['planId'] : null,
            'label' => is_string($item['label']) ? $item['label'] : '',
            'detail' => is_string($item['detail']) ? $item['detail'] : '',
        ])->values()->all();

        return new BillAuditSection($rows, $items->count(), $items->count() > self::SECTION_LIMIT);
    }

    private function normalise(string $description): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', '', $description));
    }
}
