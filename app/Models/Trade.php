<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\TradeActivity;
use Carbon\CarbonImmutable;
use Database\Factories\TradeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One investment activity on an account. The quantity is signed (a sell is negative) and an exact
 * decimal string; price, fee and amount are integer cents of `currency`. A trade outlives its
 * security (security_id becomes null when a security is deleted). Trades carry no user_id; ownership
 * comes from the parent account (C2), so always read them through forUser() or the account.
 *
 * @property int $id
 * @property int $account_id
 * @property int|null $security_id
 * @property int|null $original_security_id the security the trade was recorded on before a remap, null when it was never remapped
 * @property CarbonImmutable $date
 * @property string $quantity
 * @property int $price
 * @property int $fee
 * @property int $amount
 * @property string $currency
 * @property TradeActivity $activity
 * @property string|null $name
 * @property bool $exclude_from_analytics
 * @property bool $locked
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Trade extends Model
{
    /** @use HasFactory<TradeFactory> */
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
        'price',
        'fee',
        'amount',
        'currency',
        'activity',
        'name',
        'exclude_from_analytics',
        'locked',
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
            'price' => MoneyCast::class,
            'fee' => MoneyCast::class,
            'amount' => MoneyCast::class,
            'activity' => TradeActivity::class,
            'exclude_from_analytics' => 'boolean',
            'locked' => 'boolean',
        ];
    }
}
