<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BudgetTag;
use Carbon\CarbonImmutable;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property int|null $parent_id
 * @property int|null $group_id
 * @property BudgetTag|null $budget_tag
 * @property string|null $anzsic_division
 * @property string|null $anzsic_subdivision
 * @property string|null $anzsic_group
 * @property string|null $anzsic_class
 * @property string|null $icon
 * @property string|null $color
 * @property bool $is_hidden
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'parent_id',
        'budget_tag',
        'group_id',
        'anzsic_division',
        'anzsic_subdivision',
        'anzsic_group',
        'anzsic_class',
        'icon',
        'color',
        'is_hidden',
    ];

    /** @return Collection<int, self> All of the user's categories with parent relations linked in memory (no lazy loads at any depth). */
    public static function allWithLinkedParents(int $userId): Collection
    {
        $all = self::query()->where('user_id', $userId)->get();
        $byId = $all->keyBy('id');

        foreach ($all as $category) {
            $category->setRelation('parent', $category->parent_id !== null ? ($byId[$category->parent_id] ?? null) : null);
        }

        return $all;
    }

    /** @return Collection<int, self> */
    public static function visibleSortedByFullPath(int $userId): Collection
    {
        return self::allWithLinkedParents($userId)
            ->reject(fn (self $category): bool => $category->is_hidden)
            ->sortBy(fn (self $category): string => $category->fullPath())
            ->values();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsTo<CategoryGroup, $this> */
    public function ownGroup(): BelongsTo
    {
        return $this->belongsTo(CategoryGroup::class, 'group_id');
    }

    /**
     * The group this category reports under: the nearest category up the tree,
     * starting with this one, that has a group of its own.
     */
    public function effectiveGroup(): ?CategoryGroup
    {
        $visited = [];
        $cursor = $this;

        while ($cursor !== null && ! isset($visited[$cursor->id])) {
            if ($cursor->group_id !== null) {
                return $cursor->ownGroup;
            }

            $visited[$cursor->id] = true;
            $cursor = $cursor->parent;
        }

        return null;
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithRelations(Builder $query): Builder
    {
        return $query->with(['parent', 'children']);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_hidden', false);
    }

    public function fullPath(): string
    {
        $segments = [$this->name];
        $ancestor = $this->parent;

        while ($ancestor) {
            array_unshift($segments, $ancestor->name);
            $ancestor = $ancestor->parent;
        }

        return implode(' / ', $segments);
    }

    public function depth(): int
    {
        $depth = 0;
        $ancestor = $this->parent;

        while ($ancestor) {
            $depth++;
            $ancestor = $ancestor->parent;
        }

        return $depth;
    }

    public function resolveIcon(): ?string
    {
        return $this->icon ?? $this->parent?->resolveIcon();
    }

    /** @return HasMany<Budget, $this> */
    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_hidden' => 'boolean',
            'budget_tag' => BudgetTag::class,
        ];
    }
}
