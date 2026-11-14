<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InsightPriority;
use App\Enums\InsightStatus;
use App\Enums\InsightType;
use Carbon\CarbonImmutable;
use Database\Factories\InsightFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property InsightType $type
 * @property InsightPriority $priority
 * @property InsightStatus $status
 * @property string $dedup_key
 * @property string $title
 * @property string $body
 * @property array<string, mixed> $figures
 * @property array<string, mixed> $comparison
 * @property int|null $account_id
 * @property string|null $action_route
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable|null $acknowledged_at
 * @property CarbonImmutable|null $expired_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Insight extends Model
{
    /** @use HasFactory<InsightFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'type',
        'priority',
        'status',
        'dedup_key',
        'title',
        'body',
        'figures',
        'comparison',
        'account_id',
        'action_route',
        'read_at',
        'acknowledged_at',
        'expired_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFeedOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw("CASE priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => InsightType::class,
            'priority' => InsightPriority::class,
            'status' => InsightStatus::class,
            'figures' => 'array',
            'comparison' => 'array',
            'read_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
        ];
    }
}
