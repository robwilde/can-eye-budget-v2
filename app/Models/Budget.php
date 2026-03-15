<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BudgetPeriod;
use Carbon\CarbonImmutable;
use Database\Factories\BudgetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $category_id
 * @property string $name
 * @property int $limit_amount
 * @property BudgetPeriod $period
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable|null $end_date
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
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
        return $this->limit_amount - $this->transactions()->where('user_id', $this->user_id)->sum('amount');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => BudgetPeriod::class,
            'limit_amount' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }
}
