<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use Carbon\CarbonImmutable;
use Database\Factories\BudgetPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One user's budget for one period: the month's total and expected income.
 * Its per-category allocations are the Budget rows linked through budget_plan_id.
 *
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property int $total_amount
 * @property int $expected_income
 * @property CarbonImmutable|null $initialised_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class BudgetPlan extends Model
{
    /** @use HasFactory<BudgetPlanFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'period_start',
        'period_end',
        'total_amount',
        'expected_income',
        'initialised_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Budget, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'total_amount' => MoneyCast::class,
            'expected_income' => MoneyCast::class,
            'initialised_at' => 'immutable_datetime',
        ];
    }
}
