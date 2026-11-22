<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\CostBasisSource;
use Carbon\CarbonImmutable;
use Database\Factories\HoldingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of a security an account held on a date and what it was worth, in integer cents of
 * `currency`. The quantity is an exact decimal string with eight places. Holdings carry no user_id;
 * ownership comes from the parent account (C2), so always read them through forUser() or the account.
 *
 * @property int $id
 * @property int $account_id
 * @property int $security_id
 * @property int|null $original_security_id
 * @property CarbonImmutable $date
 * @property string $quantity
 * @property int $amount
 * @property string $currency
 * @property int|null $cost_basis_per_share
 * @property CostBasisSource|null $cost_basis_source
 * @property bool $cost_basis_locked
 * @property bool $provider_locked
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Holding extends Model
{
    /** @use HasFactory<HoldingFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'security_id',
        'original_security_id',
        'date',
        'quantity',
        'amount',
        'currency',
        'cost_basis_per_share',
        'cost_basis_source',
        'cost_basis_locked',
        'provider_locked',
    ];

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Security, $this> */
    public function security(): BelongsTo
    {
        return $this->belongsTo(Security::class);
    }

    /** @return BelongsTo<Security, $this> */
    public function originalSecurity(): BelongsTo
    {
        return $this->belongsTo(Security::class, 'original_security_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->whereIn(
            'account_id',
            Account::query()
                ->where('user_id', $user instanceof User ? $user->id : $user)
                ->select('id'),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'quantity' => 'decimal:8',
            'amount' => MoneyCast::class,
            'cost_basis_per_share' => MoneyCast::class,
            'cost_basis_source' => CostBasisSource::class,
            'cost_basis_locked' => 'boolean',
            'provider_locked' => 'boolean',
        ];
    }
}
