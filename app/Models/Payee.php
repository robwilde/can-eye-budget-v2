<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BudgetTag;
use App\Enums\PayeeStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PayeeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jev's category suggestion for one of a user's merchant keys, and the user's
 * answer to it.
 *
 * @property int $id
 * @property int $user_id
 * @property string $merchant_key
 * @property string $merchant_name
 * @property PayeeStatus $status
 * @property int|null $suggested_category_id
 * @property float|null $confidence
 * @property float|null $top_to_second
 * @property array<string, float>|null $probabilities category id => probability
 * @property bool $auto_applied
 * @property int|null $confirmed_category_id
 * @property BudgetTag|null $budget_tag
 * @property int|null $user_rule_id
 * @property CarbonImmutable|null $suggested_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Payee extends Model
{
    /** @use HasFactory<PayeeFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'merchant_key',
        'merchant_name',
        'status',
        'suggested_category_id',
        'confidence',
        'top_to_second',
        'probabilities',
        'auto_applied',
        'confirmed_category_id',
        'budget_tag',
        'user_rule_id',
        'suggested_at',
        'resolved_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function suggestedCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'suggested_category_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function confirmedCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'confirmed_category_id');
    }

    /** @return BelongsTo<UserRule, $this> */
    public function userRule(): BelongsTo
    {
        return $this->belongsTo(UserRule::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PayeeStatus::Pending);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', PayeeStatus::Confirmed);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PayeeStatus::class,
            'confidence' => 'float',
            'top_to_second' => 'float',
            'probabilities' => 'array',
            'auto_applied' => 'boolean',
            'budget_tag' => BudgetTag::class,
            'suggested_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
