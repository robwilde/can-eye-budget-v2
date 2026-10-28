<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\BudgetPeriod;
use App\Support\Transactions\CategoryAttribution;
use Carbon\CarbonImmutable;
use Database\Factories\BudgetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Validator;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $category_id
 * @property int|null $budget_plan_id
 * @property string $name
 * @property int|null $limit_amount null on a plan subcategory line means Shared
 * @property BudgetPeriod $period
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable|null $end_date
 * @property bool $rollover_enabled
 * @property int $rolled_over_amount
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Budget extends Model
{
    /** @use HasFactory<BudgetFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'category_id',
        'name',
        'limit_amount',
        'period',
        'start_date',
        'end_date',
        'budget_plan_id',
        'rollover_enabled',
        'rolled_over_amount',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<BudgetPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(BudgetPlan::class, 'budget_plan_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'category_id', 'category_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithRelations(Builder $query): Builder
    {
        return $query->with(['user', 'category']);
    }

    public function remaining(): int
    {
        if ($this->category_id === null) {
            return $this->limit_amount ?? 0;
        }

        $spent = (int) CategoryAttribution::query($this->user_id, forMoney: true)
            ->where('category_id', $this->category_id)
            ->sum('amount');

        return ($this->limit_amount ?? 0) - $spent;
    }

    protected static function booted(): void
    {
        self::creating(static function (self $budget): void {
            if (blank($budget->name) && $budget->category_id !== null) {
                $budget->name = (string) Category::query()->whereKey($budget->category_id)->value('name');
            }
        });

        self::saving(static function (self $budget): void {
            Validator::make($budget->only('rolled_over_amount'), [
                'rolled_over_amount' => ['nullable', 'integer', 'min:0'],
            ])->validate();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => BudgetPeriod::class,
            'limit_amount' => MoneyCast::class,
            'rollover_enabled' => 'boolean',
            'rolled_over_amount' => MoneyCast::class,
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }
}
