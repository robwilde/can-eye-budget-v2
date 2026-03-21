<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\PayFrequency;
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

    public function hasPayCycleConfigured(): bool
    {
        return $this->pay_amount !== null
            && $this->pay_frequency !== null
            && $this->next_pay_date !== null;
    }

    /** @phpstan-ignore return.unusedType */
    public function bufferUntilNextPay(int $availableToSpend): ?int
    {
        return null;
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
