<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $user_rule_group_id
 * @property string $name
 * @property string|null $description
 * @property array<int, array<string, string>> $triggers
 * @property array<int, array<string, string>> $actions
 * @property bool $strict_mode
 * @property bool $is_auto_apply
 * @property bool $is_active
 * @property int $order
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class UserRule extends Model
{
    /** @use HasFactory<UserRuleFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'user_rule_group_id',
        'name',
        'description',
        'triggers',
        'actions',
        'strict_mode',
        'is_auto_apply',
        'is_active',
        'order',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<UserRuleGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(UserRuleGroup::class, 'user_rule_group_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('order');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'triggers' => 'array',
            'actions' => 'array',
            'strict_mode' => 'boolean',
            'is_auto_apply' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
