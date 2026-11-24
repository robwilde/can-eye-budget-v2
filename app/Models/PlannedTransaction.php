<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DaysOfMonthCast;
use App\Casts\MoneyCast;
use App\DTOs\ScheduledDate;
use App\Enums\PlanAmountStrategy;
use App\Enums\PlannedTransactionStatus;
use App\Enums\PlannedTransactionType;
use App\Enums\PlanOrigin;
use App\Enums\RecurrenceFrequency;
use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Enums\TransactionDirection;
use App\Enums\WeekendAdjustment;
use App\Events\PlannedTransactionAmountChanged;
use App\Events\PlannedTransactionCategoryUpdated;
use App\Events\PlannedTransactionCreated;
use App\Events\PlannedTransactionScheduleChanged;
use App\Rules\PaymentUrl;
use App\Support\Recurrence\ScheduleGenerator;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\PlannedTransactionFactory;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * @property int $id
 * @property int $user_id
 * @property int $account_id
 * @property int|null $transfer_to_account_id
 * @property int|null $category_id
 * @property int $amount
 * @property TransactionDirection $direction
 * @property PlannedTransactionType $type
 * @property PlanOrigin $origin
 * @property string $description
 * @property string|null $merchant_key
 * @property string|null $payment_url
 * @property string|null $notes
 * @property bool $autopay
 * @property CarbonImmutable $start_date
 * @property RecurrenceFrequency $frequency
 * @property CarbonImmutable|null $until_date
 * @property int|null $end_after_count
 * @property int $recurrence_interval
 * @property list<int|string>|null $days_of_month
 * @property WeekendAdjustment $weekend_adjustment
 * @property bool $schedule_pinned
 * @property CarbonImmutable|null $renewal_on
 * @property CarbonImmutable|null $trial_ends_on
 * @property CarbonImmutable|null $cancelled_on
 * @property int $reminder_days
 * @property int $grace_days
 * @property int $amount_tolerance_bps
 * @property int $match_early_days
 * @property int $match_late_days
 * @property PlanAmountStrategy $amount_strategy
 * @property array{descriptions?: list<string>, merchant_keys?: list<string>}|null $learned_aliases
 * @property PlannedTransactionStatus $status
 * @property bool|null $is_pay_cycle_income
 * @property CarbonImmutable|null $last_generated_date
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PlannedTransaction extends Model
{
    /** @use HasFactory<PlannedTransactionFactory> */
    use HasFactory;

    public const int MAX_CONSECUTIVE_SKIPS = 400;

    /** @var list<string> */
    public const array SCHEDULE_COLUMNS = [
        'start_date',
        'frequency',
        'recurrence_interval',
        'days_of_month',
        'weekend_adjustment',
        'until_date',
        'end_after_count',
        'status',
    ];

    /** The schedule columns a cadence or end-condition edit changes; pausing a plan is not one. */
    public const array CADENCE_COLUMNS = [
        'start_date',
        'frequency',
        'recurrence_interval',
        'days_of_month',
        'weekend_adjustment',
        'until_date',
        'end_after_count',
    ];

    public const int WEEKEND_ADJUSTMENT_REACH_DAYS = 3;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'account_id',
        'transfer_to_account_id',
        'category_id',
        'amount',
        'direction',
        'type',
        'origin',
        'description',
        'merchant_key',
        'payment_url',
        'notes',
        'learned_aliases',
        'autopay',
        'start_date',
        'frequency',
        'until_date',
        'recurrence_interval',
        'days_of_month',
        'weekend_adjustment',
        'schedule_pinned',
        'end_after_count',
        'renewal_on',
        'trial_ends_on',
        'cancelled_on',
        'reminder_days',
        'grace_days',
        'amount_tolerance_bps',
        'match_early_days',
        'match_late_days',
        'amount_strategy',
        'status',
        'is_pay_cycle_income',
        'last_generated_date',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'bill',
        'origin' => 'declared',
        'autopay' => false,
        'recurrence_interval' => 1,
        'weekend_adjustment' => 'none',
        'schedule_pinned' => false,
        'reminder_days' => 3,
        'grace_days' => 3,
        'amount_tolerance_bps' => 750,
        'match_early_days' => 2,
        'match_late_days' => 7,
        'amount_strategy' => 'fixed',
        'status' => 'active',
    ];

    /** @return HasMany<PlannedTransactionPriceChange, $this> */
    public function priceChanges(): HasMany
    {
        return $this->hasMany(PlannedTransactionPriceChange::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function transferToAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'transfer_to_account_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return HasMany<PlannedOccurrence, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(PlannedOccurrence::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExcludingTransfers(Builder $query): Builder
    {
        return $query
            ->whereNull('transfer_to_account_id')
            ->whereDoesntHave('category', function (Builder $q): void {
                $q->where('name', 'Transfer')
                    ->orWhereHas(
                        'parent',
                        fn (Builder $p): Builder => $p->where('name', 'Transfer'),
                    );
            });
    }

    /**
     * Plans that move money in or out of the tracked accounts: ordinary plans plus transfers
     * with exactly one tracked side. Tracked <-> tracked and untracked <-> untracked transfers
     * net to zero for the user and stay out.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCountable(Builder $query): Builder
    {
        $tracked = fn (bool $is): Closure => fn (Builder $a): Builder => $a->where('is_tracked', $is);

        return $query->where(fn (Builder $q): Builder => $q
            ->excludingTransfers()
            ->orWhere(fn (Builder $t): Builder => $t
                ->whereNotNull('transfer_to_account_id')
                ->where(fn (Builder $sides): Builder => $sides
                    ->where(fn (Builder $s): Builder => $s
                        ->whereHas('account', $tracked(true))
                        ->whereHas('transferToAccount', $tracked(false)))
                    ->orWhere(fn (Builder $s): Builder => $s
                        ->whereHas('account', $tracked(false))
                        ->whereHas('transferToAccount', $tracked(true))))));
    }

    /**
     * Transfers from a tracked account in reports into a tracked account excluded from them.
     * They net to zero for the user, so countable() leaves them out, but they move money out
     * of Available, which skips excluded accounts.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeIntoExcludedAccount(Builder $query): Builder
    {
        return $query
            ->whereNotNull('transfer_to_account_id')
            ->whereHas('account', fn (Builder $a): Builder => $a->where('is_tracked', true)->where('exclude_from_reports', false))
            ->whereHas('transferToAccount', fn (Builder $a): Builder => $a->where('is_tracked', true)->where('exclude_from_reports', true));
    }

    /**
     * Direction the plan has for the tracked accounts, or null when it nets to zero. A transfer
     * out of a tracked account is a debit; a transfer into one is a credit, whatever side the
     * plan's own account sits on. Needs account and transferToAccount loaded for transfers.
     */
    public function countedDirection(): ?TransactionDirection
    {
        if ($this->transfer_to_account_id === null) {
            return $this->direction;
        }

        $sourceTracked = $this->account->is_tracked;
        $destinationTracked = $this->transferToAccount->is_tracked; // @phpstan-ignore property.nonObject

        return match (true) {
            $sourceTracked && ! $destinationTracked => TransactionDirection::Debit,
            ! $sourceTracked && $destinationTracked => TransactionDirection::Credit,
            default => null,
        };
    }

    /**
     * The tracked account whose balance the plan moves, the one counted in dashboard totals and
     * reports, or null when the plan nets to zero. Needs account and transferToAccount loaded.
     */
    public function countedAccount(): ?Account
    {
        if ($this->transfer_to_account_id === null) {
            return $this->account;
        }

        return match ($this->countedDirection()) {
            TransactionDirection::Debit => $this->account,
            TransactionDirection::Credit => $this->transferToAccount,
            null => null,
        };
    }

    /**
     * Whether the plan takes money out of Available, which counts tracked accounts in reports:
     * a counted debit on an account in reports, or a transfer from one into a tracked account
     * excluded from reports. Needs account and transferToAccount loaded.
     */
    public function drawsFromAvailable(): bool
    {
        if ($this->countedDirection() === TransactionDirection::Debit) {
            return $this->account->exclude_from_reports === false;
        }

        $destination = $this->transferToAccount;

        return $destination !== null
            && $this->account->is_tracked
            && ! $this->account->exclude_from_reports
            && $destination->is_tracked
            && $destination->exclude_from_reports;
    }

    /**
     * Removes a plan the user deleted: a declared plan goes with its occurrences and payments, a detected plan
     * becomes an ended tombstone (so detection never brings it back) and its pending payment suggestions are
     * superseded, as nothing may be applied to it any more.
     */
    public function discard(): void
    {
        if ($this->origin !== PlanOrigin::Detected) {
            $this->delete();

            return;
        }

        $this->update(['status' => PlannedTransactionStatus::Ended]);

        AnalysisSuggestion::query()
            ->where('user_id', $this->user_id)
            ->ofType(SuggestionType::PlannedPaymentMatch)
            ->pending()
            ->where('payload->planned_transaction_id', $this->id)
            ->update(['status' => SuggestionStatus::Superseded, 'resolved_at' => now()]);
    }

    public function isActive(): bool
    {
        return $this->status === PlannedTransactionStatus::Active;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', PlannedTransactionStatus::Active->value);
    }

    /**
     * Every plan but the tombstones of deleted detected plans, which only detection looks at.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', '!=', PlannedTransactionStatus::Ended->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUpcoming(Builder $query, ?CarbonImmutable $today = null): Builder
    {
        $today ??= CarbonImmutable::today();

        return $query
            ->active()
            ->where(function (Builder $q) use ($today): void {
                $q->whereNull('until_date')->orWhere('until_date', '>=', $today);
            })
            ->orderBy('start_date');
    }

    /**
     * The due dates inside the window, each computed from start_date, adjusted for weekends, and
     * stopping at until_date and end_after_count. It seeks to the window, so a plan that started
     * long ago costs no more than a new one.
     *
     * @return Collection<int, CarbonImmutable>
     */
    public function occurrencesBetween(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return $this->scheduledBetween($start, $end)
            ->map(static fn (ScheduledDate $date): CarbonImmutable => $date->due)
            ->values();
    }

    /**
     * Like occurrencesBetween(), keeping each date's original scheduled date beside its due date.
     *
     * @return Collection<int, ScheduledDate>
     */
    public function scheduledBetween(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $dates = collect();

        if (! $this->isActive()) {
            return $dates;
        }

        $reach = $end->addDays(self::WEEKEND_ADJUSTMENT_REACH_DAYS);
        $earliest = $start->subDays(self::WEEKEND_ADJUSTMENT_REACH_DAYS);
        $guard = (int) abs($earliest->diffInDays($reach)) + 2;
        $read = 0;

        foreach ($this->schedule($start) as $scheduled) {
            if ($scheduled->original->greaterThan($reach)) {
                break;
            }

            if ($scheduled->original->lessThan($earliest)) {
                continue;
            }

            if ($read++ >= $guard) {
                break;
            }

            if ($scheduled->due->between($start, $end)) {
                $dates->push($scheduled);
            }
        }

        return $dates;
    }

    /**
     * The final due date an end count allows, or null when the plan has no end count.
     */
    public function lastOccurrenceDate(): ?CarbonImmutable
    {
        if ($this->end_after_count === null) {
            return null;
        }

        $last = null;

        foreach ($this->dueDates() as $scheduled) {
            $last = $scheduled->due;
        }

        return $last;
    }

    /**
     * The last date the plan can fall on: the earlier of until_date and the end count's final date.
     */
    public function endsOn(): ?CarbonImmutable
    {
        $counted = $this->lastOccurrenceDate();

        if ($counted === null || $this->until_date === null) {
            return $counted ?? $this->until_date;
        }

        return $counted->lessThan($this->until_date) ? $counted : $this->until_date;
    }

    /**
     * The first due date on or after $from, respecting until_date and the end count. Unlike
     * occurrencesBetween() this is not bound to a fixed window, so a long-running daily plan still
     * resolves its next date. Null when inactive or ended.
     */
    public function nextOccurrenceOnOrAfter(CarbonImmutable $from): ?CarbonImmutable
    {
        return $this->nextScheduledOnOrAfter($from)?->due;
    }

    public function nextScheduledOnOrAfter(CarbonImmutable $from): ?ScheduledDate
    {
        if (! $this->isActive()) {
            return null;
        }

        $maxDates = (int) abs($this->start_date->diffInDays($from)) + 367;
        $read = 0;

        foreach ($this->schedule($from) as $scheduled) {
            if ($read++ >= $maxDates) {
                return null;
            }

            if ($scheduled->due->greaterThanOrEqualTo($from)) {
                return $scheduled;
            }
        }

        return null;
    }

    /**
     * Every date the plan is due, in order, up to until_date and end_after_count. Endless for a plan
     * with neither, so callers stop reading. With $from the schedule seeks to it instead of walking
     * from start_date, so it may begin a little before $from but never skips a date on or after it;
     * a plan with an end count is always walked from its start, because the count needs every date.
     *
     * @return Generator<int, ScheduledDate>
     */
    public function schedule(?CarbonImmutable $from = null): Generator
    {
        foreach ($this->dueDates($from) as $scheduled) {
            if ($this->until_date !== null && $scheduled->original->greaterThan($this->until_date)) {
                return;
            }

            yield $scheduled;
        }
    }

    public function frequencyLabel(): string
    {
        return $this->frequency->label($this->recurrence_interval);
    }

    protected static function booted(): void
    {
        self::saving(static function (PlannedTransaction $plannedTransaction): void {
            $plannedTransaction->payment_url = PaymentUrl::normalise($plannedTransaction->payment_url);

            Validator::make($plannedTransaction->only(['account_id', 'transfer_to_account_id', 'end_after_count', 'payment_url', 'recurrence_interval', 'days_of_month']), [
                'transfer_to_account_id' => ['nullable', 'integer', 'different:account_id'],
                'end_after_count' => ['nullable', 'integer', 'min:1', 'max:600'],
                'payment_url' => ['nullable', 'string', new PaymentUrl],
                'recurrence_interval' => ['required', 'integer', 'min:1', 'max:99'],
                'days_of_month' => ['nullable', 'array'],
                'days_of_month.*' => [static function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== DaysOfMonthCast::LAST && (! is_int($value) || $value < 1 || $value > 31)) {
                        $fail(__('A day of the month is 1 to 31 or last.'));
                    }
                }],
            ])->validate();

            if ($plannedTransaction->transfer_to_account_id !== null) {
                $plannedTransaction->type = PlannedTransactionType::Transfer;
            } elseif ($plannedTransaction->exists
                && $plannedTransaction->isDirty('transfer_to_account_id')
                && $plannedTransaction->type === PlannedTransactionType::Transfer) {
                $plannedTransaction->type = $plannedTransaction->direction === TransactionDirection::Credit
                    ? PlannedTransactionType::Income
                    : PlannedTransactionType::Bill;
            }
        });

        self::created(static function (PlannedTransaction $plannedTransaction): void {
            event(new PlannedTransactionCreated($plannedTransaction));
        });

        self::updated(static function (PlannedTransaction $plannedTransaction): void {
            if ($plannedTransaction->wasChanged('amount')) {
                event(new PlannedTransactionAmountChanged($plannedTransaction, (int) $plannedTransaction->getOriginal('amount')));
            }

            if ($plannedTransaction->wasChanged(self::SCHEDULE_COLUMNS)) {
                event(new PlannedTransactionScheduleChanged($plannedTransaction));
            }

            if ($plannedTransaction->wasChanged('category_id')) {
                event(new PlannedTransactionCategoryUpdated(
                    $plannedTransaction,
                    $plannedTransaction->getOriginal('category_id'),
                ));
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'direction' => TransactionDirection::class,
            'type' => PlannedTransactionType::class,
            'origin' => PlanOrigin::class,
            'frequency' => RecurrenceFrequency::class,
            'amount' => MoneyCast::class,
            'autopay' => 'boolean',
            'learned_aliases' => 'array',
            'start_date' => 'date',
            'until_date' => 'date',
            'end_after_count' => 'integer',
            'recurrence_interval' => 'integer',
            'days_of_month' => DaysOfMonthCast::class,
            'weekend_adjustment' => WeekendAdjustment::class,
            'schedule_pinned' => 'boolean',
            'renewal_on' => 'date',
            'trial_ends_on' => 'date',
            'cancelled_on' => 'date',
            'reminder_days' => 'integer',
            'grace_days' => 'integer',
            'amount_tolerance_bps' => 'integer',
            'match_early_days' => 'integer',
            'match_late_days' => 'integer',
            'amount_strategy' => PlanAmountStrategy::class,
            'last_generated_date' => 'date',
            'status' => PlannedTransactionStatus::class,
            'is_pay_cycle_income' => 'boolean',
        ];
    }

    /**
     * @return Generator<int, ScheduledDate>
     */
    private function dueDates(?CarbonImmutable $from = null): Generator
    {
        $yielded = 0;
        $skipped = 0;
        $seekTo = $from !== null && $this->end_after_count === null
            ? $from->subDays(self::WEEKEND_ADJUSTMENT_REACH_DAYS)
            : null;

        foreach (ScheduleGenerator::dates($this->frequency, $this->start_date, $this->recurrence_interval, $this->days_of_month, $seekTo) as $original) {
            $due = $this->weekend_adjustment->adjust($original);

            if ($due === null) {
                if (++$skipped > self::MAX_CONSECUTIVE_SKIPS) {
                    return;
                }

                continue;
            }

            $skipped = 0;

            if ($this->end_after_count !== null && $yielded >= $this->end_after_count) {
                return;
            }

            $yielded++;

            yield new ScheduledDate($original, $due);
        }
    }
}
