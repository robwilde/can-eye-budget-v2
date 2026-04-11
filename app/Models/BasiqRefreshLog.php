<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use Carbon\CarbonImmutable;
use Database\Factories\BasiqRefreshLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property array<int, string>|null $job_ids
 * @property RefreshTrigger $trigger
 * @property RefreshStatus $status
 * @property int|null $accounts_synced
 * @property int|null $transactions_synced
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class BasiqRefreshLog extends Model
{
    /** @use HasFactory<BasiqRefreshLogFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'job_ids',
        'trigger',
        'status',
        'accounts_synced',
        'transactions_synced',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'job_ids' => 'array',
            'trigger' => RefreshTrigger::class,
            'status' => RefreshStatus::class,
        ];
    }
}
