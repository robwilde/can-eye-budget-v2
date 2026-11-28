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
 * The plan keeps the currency it was created in: the owner's base currency at that moment.
 * Its figures, actuals and rollover chain all stay in that currency.
 *
 * Its per-category allocations are the Budget rows linked through budget_plan_id.
 *
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property int $total_amount
 * @property int $expected_income
 * @property string $currency
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
        'currency',
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

    protected static function booted(): void
    {
        self::creating(static function (self $plan): void {
            if (blank($plan->currency)) {
                $plan->currency = (string) User::query()->whereKey($plan->user_id)->value('base_currency');
            }
        });
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
