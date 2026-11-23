<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\OccurrenceStatus;
use App\Services\Recurring\PlanAmountResolver;
use Carbon\CarbonImmutable;
use Database\Factories\PlannedOccurrenceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 * @property int|string|null $payments_sum_amount
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

    /** @return HasMany<PlannedPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(PlannedPayment::class);
    }

    /** What has been paid against this occurrence, in cents; reads the preloaded sum when there is one. */
    public function paidCents(): int
    {
        return array_key_exists('payments_sum_amount', $this->attributes)
            ? (int) $this->payments_sum_amount
            : (int) $this->payments()->sum('amount');
    }

    /**
     * What the occurrence expects: its own amount, else what a history-following plan has paid before
     * (this occurrence left out), else the plan's amount. The one rule every reader uses.
     */
    public function effectiveExpectedAmount(): int
    {
        return $this->expected_amount
            ?? app(PlanAmountResolver::class)->fromHistory($this->plannedTransaction, $this->id)
            ?? $this->plannedTransaction->amount;
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
