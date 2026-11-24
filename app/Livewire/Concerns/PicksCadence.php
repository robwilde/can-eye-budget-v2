<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Casts\DaysOfMonthCast;
use App\Enums\RecurrenceFrequency;
use App\Enums\WeekendAdjustment;
use App\Models\PlannedTransaction;
use Closure;
use Illuminate\Validation\Rule;

/**
 * The cadence picker shared by the plan editors: presets, every N weeks, months or years, the days of the
 * month (including the last day) and a weekend rule. The using component keeps its own `frequency` property.
 */
trait PicksCadence
{
    public string $recurrenceInterval = '1';

    public string $daysOfMonth = '';

    public string $weekendAdjustment = 'none';

    /** @return array<string, mixed> */
    protected function cadenceRules(): array
    {
        $frequency = RecurrenceFrequency::tryFrom($this->frequency);

        return [
            'recurrenceInterval' => $frequency?->usesInterval() === true
                ? ['required', 'integer', 'between:1,'.$frequency->maxInterval()]
                : ['nullable'],
            'daysOfMonth' => $frequency?->supportsDaysOfMonth() === true
                ? ['nullable', 'string', 'max:120', $this->daysOfMonthRule($frequency === RecurrenceFrequency::Semimonthly)]
                : ['nullable'],
            'weekendAdjustment' => ['required', Rule::enum(WeekendAdjustment::class)],
        ];
    }

    /**
     * The schedule columns the picker chooses for $frequency. A plan that keeps a cadence the picker cannot
     * express leaves its interval, days and weekend rule as they are.
     *
     * @return array<string, mixed>
     */
    protected function cadenceAttributes(RecurrenceFrequency $frequency, ?PlannedTransaction $existing = null): array
    {
        if (! $frequency->isPickable() && $existing !== null && $existing->frequency === $frequency) {
            return [];
        }

        $days = $frequency->supportsDaysOfMonth() ? self::daysOfMonthTokens($this->daysOfMonth) : [];

        return [
            'recurrence_interval' => $frequency->usesInterval() ? (int) $this->recurrenceInterval : 1,
            'days_of_month' => $days === [] ? null : DaysOfMonthCast::normalise($days),
            'weekend_adjustment' => WeekendAdjustment::from($this->weekendAdjustment),
        ];
    }

    /** Whether the cadence the user chose differs from the stored one, which pins it against detection. */
    protected function cadenceChanged(PlannedTransaction $plan, RecurrenceFrequency $frequency): bool
    {
        $chosen = $this->cadenceAttributes($frequency, $plan);

        return $frequency !== $plan->frequency
            || ($chosen !== [] && (
                $chosen['recurrence_interval'] !== $plan->recurrence_interval
                || $chosen['days_of_month'] !== $plan->days_of_month
                || $chosen['weekend_adjustment'] !== $plan->weekend_adjustment
            ));
    }

    protected function fillCadenceFrom(PlannedTransaction $plan): void
    {
        $this->recurrenceInterval = (string) $plan->recurrence_interval;
        $this->daysOfMonth = implode(', ', $plan->days_of_month ?? []);
        $this->weekendAdjustment = $plan->weekend_adjustment->value;
    }

    protected function resetCadence(): void
    {
        $this->recurrenceInterval = '1';
        $this->daysOfMonth = '';
        $this->weekendAdjustment = 'none';
    }

    /**
     * The cadences to offer, plus the current one as Custom when the picker cannot express it.
     *
     * @return list<RecurrenceFrequency>
     */
    protected function cadenceOptions(?RecurrenceFrequency $current): array
    {
        $options = RecurrenceFrequency::pickable();

        return $current !== null && ! in_array($current, $options, true) ? [...$options, $current] : $options;
    }

    /** @return list<int|string>|null */
    protected function daysOfMonthList(): ?array
    {
        $tokens = self::daysOfMonthTokens($this->daysOfMonth);

        return $tokens === [] ? null : DaysOfMonthCast::normalise($tokens);
    }

    /** @return list<string> */
    private static function daysOfMonthTokens(string $value): array
    {
        return array_values(array_filter(array_map(mb_trim(...), explode(',', $value)), static fn (string $token): bool => $token !== ''));
    }

    private function daysOfMonthRule(bool $twice): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($twice): void {
            $tokens = self::daysOfMonthTokens((string) $value);

            if ($twice && $tokens !== [] && count(array_unique(array_map(mb_strtolower(...), $tokens))) !== 2) {
                $fail(__('Twice a month needs exactly two days.'));

                return;
            }

            foreach ($tokens as $token) {
                $valid = mb_strtolower($token) === DaysOfMonthCast::LAST || (preg_match('/^\d{1,2}$/', $token) === 1 && (int) $token >= 1 && (int) $token <= 31);

                if (! $valid) {
                    $fail(__('Use days from 1 to 31 or "last", separated by commas.'));

                    return;
                }
            }
        };
    }
}
