<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Casts\UserPreferencesCast;
use App\DTOs\UserPreferences;
use App\Enums\PayFrequency;
use App\Services\CurrencyConverter;
use App\Support\Currencies;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int|null $pay_amount
 * @property PayFrequency|null $pay_frequency
 * @property CarbonImmutable|null $next_pay_date
 * @property int|null $primary_account_id
 * @property string $base_currency
 * @property string|null $timezone
 * @property int|null $month_start_day
 * @property UserPreferences $preferences
 */
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'base_currency' => 'AUD',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'pay_amount',
        'pay_frequency',
        'next_pay_date',
        'primary_account_id',
        'base_currency',
        'timezone',
        'month_start_day',
        'preferences',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    /** @return HasMany<Account, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return HasMany<Budget, $this> */
    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    /** @return HasMany<BudgetPlan, $this> */
    public function budgetPlans(): HasMany
    {
        return $this->hasMany(BudgetPlan::class);
    }

    /** @return HasMany<PlannedTransaction, $this> */
    public function plannedTransactions(): HasMany
    {
        return $this->hasMany(PlannedTransaction::class);
    }

    /** @return HasOne<GmailCredential, $this> */
    public function gmailCredential(): HasOne
    {
        return $this->hasOne(GmailCredential::class);
    }

    /** @return HasOne<RedbarkFeed, $this> */
    public function redbarkFeed(): HasOne
    {
        return $this->hasOne(RedbarkFeed::class);
    }

    /** @return HasMany<RedbarkSyncLog, $this> */
    public function redbarkSyncLogs(): HasMany
    {
        return $this->hasMany(RedbarkSyncLog::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function primaryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'primary_account_id');
    }

    /** @return HasMany<PipelineRun, $this> */
    public function pipelineRuns(): HasMany
    {
        return $this->hasMany(PipelineRun::class);
    }

    /** @return HasMany<AnalysisSuggestion, $this> */
    public function analysisSuggestions(): HasMany
    {
        return $this->hasMany(AnalysisSuggestion::class);
    }

    /** @return HasMany<UserRuleGroup, $this> */
    public function userRuleGroups(): HasMany
    {
        return $this->hasMany(UserRuleGroup::class);
    }

    /** @return HasMany<UserRule, $this> */
    public function userRules(): HasMany
    {
        return $this->hasMany(UserRule::class);
    }

    /** @return HasMany<Payee, $this> */
    public function payees(): HasMany
    {
        return $this->hasMany(Payee::class);
    }

    /** @return HasMany<Tag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /** @return HasMany<UserCategoryBudgetTag, $this> */
    public function categoryBudgetTags(): HasMany
    {
        return $this->hasMany(UserCategoryBudgetTag::class);
    }

    /** @return HasMany<Category, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** @return HasMany<CategoryGroup, $this> */
    public function categoryGroups(): HasMany
    {
        return $this->hasMany(CategoryGroup::class);
    }

    public function hasPayCycleConfigured(): bool
    {
        return $this->pay_amount !== null
            && $this->pay_frequency !== null
            && $this->next_pay_date !== null;
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    public function currentPayCycleBounds(): ?array
    {
        if (! $this->hasPayCycleConfigured()) {
            return null;
        }

        $frequency = $this->pay_frequency;

        if ($frequency === null) {
            return null;
        }

        $nextPay = CarbonImmutable::instance($this->next_pay_date);
        $today = CarbonImmutable::today();

        if ($nextPay->lessThanOrEqualTo($today)) {
            $nextPay = $this->fastForwardPayDate($nextPay, $today, $frequency);
        }

        $cycleStart = match ($frequency) {
            PayFrequency::Weekly => $nextPay->subWeek(),
            PayFrequency::Fortnightly => $nextPay->subWeeks(2),
            PayFrequency::Monthly => $nextPay->subMonth(),
        };

        return [
            'start' => $cycleStart,
            'end' => $nextPay,
        ];
    }

    public function totalOwed(): int
    {
        return $this->sumInBaseCurrency(
            $this->accounts()
                ->active()
                ->tracked()
                ->inReports()
                ->get(),
            fn (Account $a): int => $a->amountOwed(),
        );
    }

    public function totalAvailable(): int
    {
        return $this->sumInBaseCurrency(
            $this->accounts()
                ->active()
                ->tracked()
                ->inReports()
                ->get()
                ->filter(fn (Account $a) => $a->type->isSpendable()),
            fn (Account $a): int => $a->availableBalance(),
        );
    }

    public function eligiblePrimaryAccount(int $accountId): ?Account
    {
        return $this->accounts()->eligibleAsPrimary()->whereKey($accountId)->first();
    }

    public function daysUntilNextPay(): ?int
    {
        if (! $this->hasPayCycleConfigured()) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->next_pay_date, false);
    }

    public function bufferUntilNextPay(int $availableToSpend): ?int
    {
        if ($this->daysUntilNextPay() === null) {
            return null;
        }

        return $availableToSpend - $this->totalNeededUntilPayday();
    }

    public function totalNeededUntilPayday(): int
    {
        $bounds = $this->currentPayCycleBounds();

        if ($bounds === null) {
            return 0;
        }

        $today = CarbonImmutable::today();

        if ($today->greaterThanOrEqualTo($bounds['end'])) {
            return 0;
        }

        $converter = CurrencyConverter::enabled() ? app(CurrencyConverter::class) : null;

        return (int) $this->plannedTransactions()
            ->where('is_active', true)
            ->where(static fn ($query) => $query->countable()->orWhere(static fn ($transfers) => $transfers->intoExcludedAccount()))
            ->with(['account:id,currency,is_tracked,exclude_from_reports', 'transferToAccount:id,is_tracked,exclude_from_reports'])
            ->get()
            ->filter(static fn (PlannedTransaction $plan): bool => $plan->drawsFromAvailable())
            ->sum(function (PlannedTransaction $plan) use ($today, $bounds, $converter): int {
                $occurrences = $plan->occurrencesBetween($today, $bounds['end']);
                $amount = abs($plan->amount);

                if ($converter === null) {
                    return $occurrences->count() * $amount;
                }

                return (int) $occurrences->sum(fn (CarbonImmutable $date): int => $converter->convert($amount, $plan->account->currency, $this->base_currency, $date));
            });
    }

    /**
     * Merge the given keys into the stored preferences, re-reading the row under a lock so concurrent
     * updates to other keys are kept, and write only the preferences column.
     *
     * @param  array<string, mixed>  $changes
     */
    public function updatePreferences(array $changes): void
    {
        $this->getConnection()->transaction(function () use ($changes): void {
            $locked = self::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            $locked->preferences = UserPreferences::from([...$locked->preferences->toArray(), ...$changes]);
            $locked->save();

            $this->preferences = $locked->preferences;
            $this->syncOriginalAttribute('preferences');
        });
    }

    /**
     * The currencies the pickers offer: every supported currency unless the user narrowed the
     * list, and always including their base currency.
     *
     * @return list<string>
     */
    public function allowedCurrencies(): array
    {
        $chosen = $this->preferences->allowedCurrencies;

        return array_values(array_unique([$this->base_currency, ...($chosen === [] ? Currencies::codes() : $chosen)]));
    }

    public function resolvedTimezone(): string
    {
        return $this->timezone ?? (string) config('app.timezone');
    }

    /**
     * Deleting a user cascades their accounts, transactions and receipts in SQL, which fires no
     * Transaction events, so the receipt files are collected first and removed after the delete.
     */
    protected static function booted(): void
    {
        self::deleting(static function (self $user): void {
            TransactionAttachment::remember($user, TransactionAttachment::query()->forUser($user)->get(['disk', 'path']));
        });

        self::deleted(static function (self $user): void {
            TransactionAttachment::purgeRemembered($user);
        });
    }

    /**
     * @return Attribute<int|null, int|null>
     */
    protected function monthStartDay(): Attribute
    {
        return Attribute::set(static function (mixed $value): ?int {
            $day = $value === null || $value === '' ? null : (int) $value;

            if ($day !== null && ($day < 1 || $day > 28)) {
                throw new InvalidArgumentException('Month start day must be between 1 and 28.');
            }

            return $day;
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pay_amount' => MoneyCast::class,
            'pay_frequency' => PayFrequency::class,
            'next_pay_date' => 'date',
            'preferences' => UserPreferencesCast::class,
        ];
    }

    /**
     * With budget.multi_currency on, each account's amount converts into the base currency at
     * today's rate; off, the cents add up as stored.
     *
     * @param  Collection<int, Account>  $accounts
     * @param  Closure(Account): int  $amount
     */
    private function sumInBaseCurrency(Collection $accounts, Closure $amount): int
    {
        if (! CurrencyConverter::enabled()) {
            return (int) $accounts->sum($amount);
        }

        $converter = app(CurrencyConverter::class);
        $today = CarbonImmutable::today();

        return (int) $accounts->sum(fn (Account $a): int => $converter->convert($amount($a), $a->currency, $this->base_currency, $today));
    }

    private function fastForwardPayDate(
        CarbonImmutable $nextPay,
        CarbonImmutable $today,
        PayFrequency $frequency,
    ): CarbonImmutable {
        $intervalsToSkip = match ($frequency) {
            PayFrequency::Weekly => intdiv((int) $nextPay->diffInDays($today), 7),
            PayFrequency::Fortnightly => intdiv((int) $nextPay->diffInDays($today), 14),
            PayFrequency::Monthly => (int) $nextPay->diffInMonths($today),
        };

        if ($intervalsToSkip > 0) {
            $nextPay = match ($frequency) {
                PayFrequency::Weekly => $nextPay->addWeeks($intervalsToSkip),
                PayFrequency::Fortnightly => $nextPay->addWeeks($intervalsToSkip * 2),
                PayFrequency::Monthly => $nextPay->addMonths($intervalsToSkip),
            };
        }

        $interval = match ($frequency) {
            PayFrequency::Weekly => static fn (CarbonImmutable $d) => $d->addWeek(),
            PayFrequency::Fortnightly => static fn (CarbonImmutable $d) => $d->addWeeks(2),
            PayFrequency::Monthly => static fn (CarbonImmutable $d) => $d->addMonth(),
        };

        while ($nextPay->lessThanOrEqualTo($today)) {
            $nextPay = $interval($nextPay);
        }

        return $nextPay;
    }
}
