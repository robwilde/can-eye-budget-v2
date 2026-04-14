<?php

declare(strict_types=1);

namespace App\Models;

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
 * @property string $name
 * @property int|null $parent_id
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
        'name',
        'parent_id',
        'anzsic_division',
        'anzsic_subdivision',
        'anzsic_group',
        'anzsic_class',
        'icon',
        'color',
        'is_hidden',
    ];

    /** @return Collection<int, self> */
    public static function visibleSortedByFullPath(): Collection
    {
        return self::query()
            ->visible()
            ->with(['parent', 'parent.parent'])
            ->get()
            ->sortBy(fn (self $category): string => $category->fullPath())
            ->values();
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
        ];
    }
}
