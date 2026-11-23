<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use Carbon\CarbonImmutable;
use Database\Factories\PlannedPaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money paid against one occurrence, in cents and in the plan's currency. It may come from a
 * transaction, which can fund several occurrences up to its own amount, or be recorded by hand
 * without one. Payments carry no user_id; ownership comes from the occurrence's plan (C2).
 *
 * @property int $id
 * @property int $planned_occurrence_id
 * @property int|null $transaction_id
 * @property int $amount
 * @property CarbonImmutable $paid_on
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PlannedPayment extends Model
{
    /** @use HasFactory<PlannedPaymentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'planned_occurrence_id',
        'transaction_id',
        'amount',
        'paid_on',
    ];

    /** @return BelongsTo<PlannedOccurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(PlannedOccurrence::class, 'planned_occurrence_id');
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->whereIn(
            'planned_occurrence_id',
            PlannedOccurrence::query()->forUser($user)->select('id'),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'paid_on' => 'immutable_date',
        ];
    }
}
