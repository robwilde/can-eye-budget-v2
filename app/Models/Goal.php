<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\GoalKind;
use App\Enums\GoalStatus;
use Carbon\CarbonImmutable;
use Database\Factories\GoalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property GoalKind $kind
 * @property GoalStatus $status
 * @property int $target_amount
 * @property CarbonImmutable|null $target_date
 * @property int|null $target_months_of_expenses
 * @property string|null $colour
 * @property string|null $icon
 * @property string|null $notes
 * @property int|null $frozen_saved_amount
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Goal extends Model
{
    /** @use HasFactory<GoalFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'kind',
        'status',
        'target_amount',
        'target_date',
        'target_months_of_expenses',
        'colour',
        'icon',
        'notes',
        'frozen_saved_amount',
        'completed_at',
        'archived_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => 'one_off',
        'status' => 'active',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<GoalAccount, $this> */
    public function goalAccounts(): HasMany
    {
        return $this->hasMany(GoalAccount::class);
    }

    /** @return HasMany<GoalPledge, $this> */
    public function pledges(): HasMany
    {
        return $this->hasMany(GoalPledge::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => GoalKind::class,
            'status' => GoalStatus::class,
            'target_amount' => MoneyCast::class,
            'target_date' => 'immutable_date',
            'frozen_saved_amount' => MoneyCast::class,
            'completed_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }
}
