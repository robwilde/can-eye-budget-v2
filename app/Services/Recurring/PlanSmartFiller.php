<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\Console\Commands\JevEvalCommand;
use App\Contracts\TypeSafeServiceContract;
use App\DTOs\PlanSmartFillSuggestion;
use App\Enums\PlannedTransactionType;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Exceptions\TypeSafe\TypeSafeException;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Recurrence\NextDueDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Proposes a plan's name, amount, cadence, due day, category, type and autopay from the user's own past
 * charges, one TypeSafe Choice question per field, for a person to review before anything is saved.
 *
 * Data minimisation: only the matching charges leave the app, at most 40 of them, as a masked name, an
 * amount and a date, plus the option labels (cadence and type names, the user's category names). No
 * account, notes, user identity or other user's data is sent. An answer under MIN_CONFIDENCE leaves its
 * field alone. A person is waiting, so the injected model client is the short-timeout one with no retries
 * and the questions stop once BUDGET_SECONDS have passed.
 */
final readonly class PlanSmartFiller
{
    public const int MAX_CHARGES = 40;

    public const float MIN_CONFIDENCE = 0.6;

    public const int BUDGET_SECONDS = 30;

    public const int HOURLY_LIMIT = 20;

    private const int MAX_NAME_OPTIONS = 8;

    private const int MAX_AMOUNT_OPTIONS = 8;

    private const int MAX_CATEGORY_OPTIONS = 255;

    public function __construct(
        private TypeSafeServiceContract $typeSafe,
        private PlanTransactionSearch $charges,
    ) {}

    /** Whether smart fill may be offered and run: the flag is on and a TypeSafe key is configured. */
    public static function available(): bool
    {
        return config('budget.plan_smart_fill') === true && filled(config('services.typesafe.api_key'));
    }

    /** Takes one of the user's hourly smart fill uses; false once they are spent. */
    public function allow(User $user): bool
    {
        return RateLimiter::attempt('plan-smart-fill:'.$user->id, self::HOURLY_LIMIT, static fn (): bool => true, 3600) === true;
    }

    /**
     * Null when the user has no past charge that looks like the description, so there is nothing to
     * fill from; a suggestion otherwise, with only the fields the model was sure of. Income is never asked
     * about autopay, and $withName false skips the name question for a caller that would not use it.
     * $currency keeps charges in other currencies out, so amounts are never mixed.
     *
     * @throws TypeSafeException
     */
    public function suggest(User $user, string $description, TransactionDirection $direction, ?string $currency = null, bool $withName = true): ?PlanSmartFillSuggestion
    {
        $charges = $this->charges->matching($user, $description, $direction, self::MAX_CHARGES, $currency);

        if ($charges->isEmpty()) {
            return null;
        }

        $deadline = microtime(true) + self::BUDGET_SECONDS;
        $state = [
            'direction' => $direction->value,
            'charges' => $charges->map(static fn (Transaction $charge): array => [
                'description' => JevEvalCommand::maskDigits(self::nameOf($charge)),
                'amount' => abs($charge->amount),
                'date' => $charge->post_date->toDateString(),
            ])->values()->all(),
        ];

        $frequency = $this->choose($state, 'How often does this payment repeat?', $this->frequencyOptions(), $deadline);
        $type = $this->choose($state, 'What kind of payment is this?', $this->typeOptions($direction), $deadline);
        $autopay = $direction === TransactionDirection::Credit
            ? null
            : $this->choose($state, 'Is this taken automatically (direct debit, card on file, subscription) or paid by hand each time?', [
                'yes' => 'Taken automatically by the biller',
                'no' => 'Paid by the person each time',
            ], $deadline);

        return new PlanSmartFillSuggestion(
            description: $withName ? $this->name($charges, $state, $deadline) : null,
            amount: $this->amount($charges, $state, $deadline),
            frequency: $frequency,
            startDate: $frequency === null ? null : NextDueDate::onDay(
                RecurrenceFrequency::from($frequency),
                CarbonImmutable::instance($charges->first()->post_date),
                $user->today(),
                $this->usualDay($charges),
            )->toDateString(),
            categoryId: $this->category($user, $charges, $state, $deadline),
            type: $type,
            autopay: $autopay === null ? null : $autopay === 'yes',
        );
    }

    private static function nameOf(Transaction $charge): string
    {
        return filled($charge->merchant_name) ? (string) $charge->merchant_name : (filled($charge->clean_description) ? (string) $charge->clean_description : (string) $charge->description);
    }

    /**
     * The day of the month the charges usually land on; the later day when two are equally common, so a
     * bill due on the 31st that landed on the 28th in February still reads as the 31st.
     *
     * @param  Collection<int, Transaction>  $charges
     */
    private function usualDay(Collection $charges): int
    {
        $counts = $charges->countBy(static fn (Transaction $charge): int => $charge->post_date->day)->all();
        krsort($counts);
        arsort($counts);

        return (int) array_key_first($counts);
    }

    /**
     * @param  Collection<int, Transaction>  $charges
     * @param  array<string, mixed>  $state
     */
    private function name(Collection $charges, array $state, float $deadline): ?string
    {
        $byMasked = [];

        foreach ($charges as $charge) {
            $byMasked[JevEvalCommand::maskDigits(self::nameOf($charge))] ??= self::nameOf($charge);

            if (count($byMasked) >= self::MAX_NAME_OPTIONS) {
                break;
            }
        }

        if (count($byMasked) === 1) {
            return array_values($byMasked)[0];
        }

        $options = [];
        $names = [];

        foreach (array_values($byMasked) as $index => $original) {
            $options['n'.$index] = JevEvalCommand::maskDigits($original);
            $names['n'.$index] = $original;
        }

        $choice = $this->choose($state, 'Which of these names is the best name for this bill?', $options, $deadline);

        return $choice === null ? null : $names[$choice];
    }

    /**
     * @param  Collection<int, Transaction>  $charges
     * @param  array<string, mixed>  $state
     */
    private function amount(Collection $charges, array $state, float $deadline): ?int
    {
        $amounts = $charges->map(static fn (Transaction $charge): int => abs($charge->amount))->unique()->take(self::MAX_AMOUNT_OPTIONS)->values();

        if ($amounts->count() === 1) {
            return $amounts->first();
        }

        $choice = $this->choose(
            $state,
            'Which amount, in cents, is the usual amount of this payment?',
            $amounts->mapWithKeys(static fn (int $amount): array => [(string) $amount => number_format($amount / 100, 2, '.', '').' dollars'])->all(),
            $deadline,
        );

        return $choice === null ? null : (int) $choice;
    }

    /**
     * @param  Collection<int, Transaction>  $charges
     * @param  array<string, mixed>  $state
     */
    private function category(User $user, Collection $charges, array $state, float $deadline): ?int
    {
        $used = $charges->pluck('category_id')->filter()->unique()->all();
        $categories = Category::visibleSortedByFullPath($user->id)
            ->sortBy(static fn (Category $category): int => in_array($category->id, $used, true) ? 0 : 1)
            ->take(self::MAX_CATEGORY_OPTIONS);

        if ($categories->count() < 2) {
            return null;
        }

        $choice = $this->choose(
            $state,
            'Which budget category does this payment belong to?',
            $categories->mapWithKeys(static fn (Category $category): array => [(string) $category->id => $category->fullPath()])->all(),
            $deadline,
        );

        return $choice === null ? null : (int) $choice;
    }

    /** @return array<string, string> */
    private function frequencyOptions(): array
    {
        $options = [];

        foreach (RecurrenceFrequency::pickable() as $frequency) {
            if ($frequency !== RecurrenceFrequency::DontRepeat && ! $frequency->usesInterval()) {
                $options[$frequency->value] = $frequency->label();
            }
        }

        return $options;
    }

    /** @return array<string, string> */
    private function typeOptions(TransactionDirection $direction): array
    {
        $options = [];

        foreach (PlannedTransactionType::selectableFor($direction) as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }

    /**
     * The model's choice, or null when it was not sure enough.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, string>  $options
     *
     * @throws TypeSafeException
     */
    private function choose(array $state, string $instructions, array $options, float $deadline): ?string
    {
        if (microtime(true) > $deadline) {
            throw new TypeSafeException('Smart fill ran out of time.');
        }

        $answer = $this->typeSafe->choose($state, $instructions, $options);

        return $answer->confidence >= self::MIN_CONFIDENCE && array_key_exists($answer->choice, $options) ? $answer->choice : null;
    }
}
