<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\PlanAmountStrategy;
use App\Enums\PlannedTransactionType;
use App\Enums\PlanOrigin;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Events\PlannedTransactionCategoryUpdated;
use App\Events\PlannedTransactionCreated;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\PlannedTransactionFactory;
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
 * @property CarbonImmutable|null $renewal_on
 * @property CarbonImmutable|null $trial_ends_on
 * @property CarbonImmutable|null $cancelled_on
 * @property int $reminder_days
 * @property int $grace_days
 * @property int $amount_tolerance_bps
 * @property int $match_early_days
 * @property int $match_late_days
 * @property PlanAmountStrategy $amount_strategy
 * @property bool $is_active
 * @property bool|null $is_pay_cycle_income
 * @property CarbonImmutable|null $last_generated_date
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PlannedTransaction extends Model
{
    /** @use HasFactory<PlannedTransactionFactory> */
    use HasFactory;

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
        'autopay',
        'start_date',
        'frequency',
        'until_date',
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
        'is_active',
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
        'reminder_days' => 3,
        'grace_days' => 3,
        'amount_tolerance_bps' => 750,
        'match_early_days' => 2,
        'match_late_days' => 7,
        'amount_strategy' => 'fixed',
    ];

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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUpcoming(Builder $query, ?CarbonImmutable $today = null): Builder
    {
        $today ??= CarbonImmutable::today();

        return $query
            ->where('is_active', true)
            ->where(function (Builder $q) use ($today): void {
                $q->whereNull('until_date')->orWhere('until_date', '>=', $today);
            })
            ->orderBy('start_date');
    }

    /** @return Collection<int, CarbonImmutable> */
    public function occurrencesBetween(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        if (! $this->is_active) {
            return collect();
        }

        $dates = collect();
        $current = $this->start_date;
        $limit = $this->until_date;
        $maxIterations = 1000;

        if ($this->frequency === RecurrenceFrequency::DontRepeat) {
            if ($current->between($start, $end) && ($limit === null || $current->lte($limit))) {
                $dates->push($current);
            }

            return $dates;
        }

        for ($i = 0; $i < $maxIterations; $i++) {
            if ($current->greaterThan($end)) {
                break;
            }

            if ($limit !== null && $current->greaterThan($limit)) {
                break;
            }

            if ($current->between($start, $end)) {
                $dates->push($current);
            }

            $next = $this->frequency->nextOccurrence($current);

            if ($next === null) {
                break;
            }

            $current = $next;
        }

        return $dates;
    }

    /**
     * The first occurrence on or after $from, respecting until_date, advancing
     * from start_date so the cadence anchor (e.g. day-of-month) is preserved.
     * Unlike occurrencesBetween() this is not bound to a fixed window, so a
     * long-running daily plan still resolves its next date. Null when inactive
     * or ended.
     */
    public function nextOccurrenceOnOrAfter(CarbonImmutable $from): ?CarbonImmutable
    {
        if (! $this->is_active) {
            return null;
        }

        $current = $this->start_date;

        if ($this->frequency === RecurrenceFrequency::DontRepeat) {
            if ($this->until_date !== null && $current->greaterThan($this->until_date)) {
                return null;
            }

            return $current->greaterThanOrEqualTo($from) ? $current : null;
        }

        $maxIterations = (int) abs($this->start_date->diffInDays($from)) + 367;

        for ($i = 0; $i < $maxIterations; $i++) {
            if ($this->until_date !== null && $current->greaterThan($this->until_date)) {
                return null;
            }

            if ($current->greaterThanOrEqualTo($from)) {
                return $current;
            }

            $next = $this->frequency->nextOccurrence($current);

            if ($next === null) {
                return null;
            }

            $current = $next;
        }

        return null;
    }

    protected static function booted(): void
    {
        self::saving(static function (PlannedTransaction $plannedTransaction): void {
            Validator::make($plannedTransaction->only(['account_id', 'transfer_to_account_id', 'end_after_count']), [
                'transfer_to_account_id' => ['nullable', 'integer', 'different:account_id'],
                'end_after_count' => ['nullable', 'integer', 'min:1', 'max:600'],
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
            'start_date' => 'date',
            'until_date' => 'date',
            'end_after_count' => 'integer',
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
            'is_active' => 'boolean',
            'is_pay_cycle_income' => 'boolean',
        ];
    }
}
