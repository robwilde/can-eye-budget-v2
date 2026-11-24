<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use Carbon\CarbonImmutable;
use Database\Factories\PlannedTransactionPriceChangeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A price rise (or fall) a plan learned from consistent payments, in cents. Owned through its plan.
 *
 * @property int $id
 * @property int $planned_transaction_id
 * @property int $previous_amount
 * @property int $new_amount
 * @property CarbonImmutable $effective_on
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PlannedTransactionPriceChange extends Model
{
    /** @use HasFactory<PlannedTransactionPriceChangeFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'planned_transaction_id',
        'previous_amount',
        'new_amount',
        'effective_on',
    ];

    /** @return BelongsTo<PlannedTransaction, $this> */
    public function plannedTransaction(): BelongsTo
    {
        return $this->belongsTo(PlannedTransaction::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->whereIn(
            'planned_transaction_id',
            PlannedTransaction::query()->where('user_id', $user instanceof User ? $user->id : $user)->select('id'),
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'previous_amount' => MoneyCast::class,
            'new_amount' => MoneyCast::class,
            'effective_on' => 'immutable_date',
        ];
    }
}
