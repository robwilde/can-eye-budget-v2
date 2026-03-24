<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\AccountClass;
use App\Enums\AccountStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $basiq_account_id
 * @property string $name
 * @property AccountClass $type
 * @property string $institution
 * @property string $currency
 * @property int $balance
 * @property int|null $credit_limit
 * @property int|null $available_funds
 * @property AccountStatus $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'basiq_account_id',
        'name',
        'type',
        'institution',
        'currency',
        'balance',
        'credit_limit',
        'available_funds',
        'status',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [AccountStatus::Active, AccountStatus::Available]);
    }

    public function availableBalance(): int
    {
        if ($this->type === AccountClass::CreditCard) {
            return ($this->credit_limit ?? 0) + $this->balance;
        }

        return $this->balance;
    }

    public function amountOwed(): int
    {
        if ($this->type === AccountClass::CreditCard || $this->type === AccountClass::Loan) {
            return abs($this->balance);
        }

        return 0;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithRelations(Builder $query): Builder
    {
        return $query->with(['user', 'transactions']);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountClass::class,
            'status' => AccountStatus::class,
            'balance' => MoneyCast::class,
            'credit_limit' => MoneyCast::class,
            'available_funds' => MoneyCast::class,
        ];
    }
}
