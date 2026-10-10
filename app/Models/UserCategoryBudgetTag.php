<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BudgetTag;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $category_id
 * @property BudgetTag $budget_tag
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class UserCategoryBudgetTag extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'category_id',
        'budget_tag',
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'budget_tag' => BudgetTag::class,
        ];
    }
}
