<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\AccountClass;
use App\Enums\PayFrequency;
use App\Enums\TransactionDirection;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property CarbonImmutable|null $last_synced_at
 * @property int|null $pay_amount
 * @property PayFrequency|null $pay_frequency
 * @property CarbonImmutable|null $next_pay_date
 * @property int|null $primary_account_id
 */
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'basiq_user_id',
        'last_synced_at',
        'password',
        'pay_amount',
        'pay_frequency',
        'next_pay_date',
        'primary_account_id',
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

    /** @return HasMany<PlannedTransaction, $this> */
    public function plannedTransactions(): HasMany
    {
        return $this->hasMany(PlannedTransaction::class);
    }

    /** @return HasMany<BasiqRefreshLog, $this> */
    public function basiqRefreshLogs(): HasMany
    {
        return $this->hasMany(BasiqRefreshLog::class);
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
        return $this->accounts()
            ->active()
            ->whereIn('type', [AccountClass::CreditCard, AccountClass::Loan])
            ->get()
            ->sum(fn (Account $a) => $a->amountOwed());
    }

    public function totalAvailable(): int
    {
        return $this->accounts()
            ->active()
            ->get()
            ->filter(fn (Account $a) => $a->type->isSpendable())
            ->sum(fn (Account $a) => $a->availableBalance());
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

        return (int) $this->plannedTransactions()
            ->where('is_active', true)
            ->where('direction', TransactionDirection::Debit)
            ->excludingTransfers()
            ->get()
            ->sum(static fn (PlannedTransaction $plan) => $plan->occurrencesBetween($today, $bounds['end'])->count() * abs($plan->amount));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'password' => 'hashed',
            'pay_amount' => MoneyCast::class,
            'pay_frequency' => PayFrequency::class,
            'next_pay_date' => 'date',
        ];
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
