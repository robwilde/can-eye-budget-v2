<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\OccurrenceStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PlannedOccurrenceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $planned_transaction_id
 * @property CarbonImmutable $original_date
 * @property CarbonImmutable $due_date
 * @property CarbonImmutable|null $snoozed_until
 * @property OccurrenceStatus $status
 * @property int|null $expected_amount
 * @property CarbonImmutable|null $closed_at
 * @property bool $closed_manually
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PlannedOccurrence extends Model
{
    /** @use HasFactory<PlannedOccurrenceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'planned_transaction_id',
        'original_date',
        'due_date',
        'snoozed_until',
        'status',
        'expected_amount',
        'closed_at',
        'closed_manually',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'scheduled',
        'closed_manually' => false,
    ];

    /** @return BelongsTo<PlannedTransaction, $this> */
    public function plannedTransaction(): BelongsTo
    {
        return $this->belongsTo(PlannedTransaction::class);
    }

    /**
     * Occurrences carry no user_id; ownership comes from the parent plan (C2).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->whereIn(
            'planned_transaction_id',
            PlannedTransaction::query()
                ->where('user_id', $user instanceof User ? $user->id : $user)
                ->select('id'),
        );
    }

    public function effectiveExpectedAmount(): int
    {
        return $this->expected_amount ?? $this->plannedTransaction->amount;
    }

    public function effectiveDueDate(): CarbonImmutable
    {
        return $this->snoozed_until ?? $this->due_date;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'original_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'snoozed_until' => 'immutable_date',
            'status' => OccurrenceStatus::class,
            'expected_amount' => MoneyCast::class,
            'closed_at' => 'immutable_datetime',
            'closed_manually' => 'boolean',
        ];
    }
}
