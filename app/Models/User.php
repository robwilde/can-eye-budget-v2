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

    public function hasPayCycleConfigured(): bool
    {
        return $this->pay_amount !== null
            && $this->pay_frequency !== null
            && $this->next_pay_date !== null;
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

    public function averageDailySpending(int $lookbackDays = 30): int
    {
        if ($lookbackDays <= 0) {
            return 0;
        }

        $totalDebits = $this->transactions()
            ->current()
            ->where('direction', TransactionDirection::Debit)
            ->where('post_date', '>=', now()->subDays($lookbackDays))
            ->sum('amount');

        return abs(intdiv((int) $totalDebits, $lookbackDays));
    }

    public function bufferUntilNextPay(int $availableToSpend): ?int
    {
        $daysUntilPay = $this->daysUntilNextPay();

        if ($daysUntilPay === null) {
            return null;
        }

        $projectedSpend = $this->averageDailySpending() * $daysUntilPay;

        return $availableToSpend - $projectedSpend;
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
}
