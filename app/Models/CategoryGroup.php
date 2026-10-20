<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CategoryGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $color
 * @property string $icon
 * @property int $sort_order
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class CategoryGroup extends Model
{
    /** @use HasFactory<CategoryGroupFactory> */
    use HasFactory;

    public const array ICONS = [
        'receipt-percent',
        'shopping-bag',
        'building-office',
        'academic-cap',
        'user',
        'activity',
        'shield-check',
        'sparkles',
        'truck',
        'credit-card',
        'arrow-trending-up',
        'arrows-right-left',
        'banknotes',
        'home',
        'heart',
        'gift',
        'bolt',
        'wrench-screwdriver',
        'shopping-cart',
        'book-open-text',
    ];

    public const string COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'color',
        'icon',
        'sort_order',
    ];

    /** @return HasMany<Category, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class, 'group_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
