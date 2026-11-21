<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Support\Rules\RuleTriggers;
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
 * @property array<int, array<string, mixed>> $triggers
 * @property array<int, array<string, string>> $actions
 * @property bool $strict_mode
 * @property bool $is_auto_apply
 * @property bool $is_active
 * @property CarbonImmutable|null $effective_from
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
        'effective_from',
        'order',
    ];

    /**
     * The first condition and how many more follow, whether top-level or inside the group.
     */
    public function conditionSummary(): string
    {
        $conditions = RuleTriggers::flatten($this->triggers);

        if ($conditions === []) {
            return '';
        }

        $field = RuleTriggerField::tryFrom((string) ($conditions[0]['field'] ?? ''));
        $operator = RuleTriggerOperator::tryFrom((string) ($conditions[0]['operator'] ?? ''));
        $value = is_scalar($conditions[0]['value'] ?? null) ? (string) $conditions[0]['value'] : '';
        $first = mb_trim(implode(' ', array_filter([$field?->label(), $operator?->label(), $value], static fn (?string $part): bool => $part !== null && $part !== '')));
        $more = count($conditions) - 1;

        return $first.($more > 0 ? " and {$more} more" : '');
    }

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
            'effective_from' => 'date',
        ];
    }
}
