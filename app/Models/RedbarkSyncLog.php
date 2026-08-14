<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use Carbon\CarbonImmutable;
use Database\Factories\RedbarkSyncLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The per-run record of a Redbark sync, and the user-facing error surface: the
 * providers settings panel renders the last ten of these, expanding $errors per row.
 *
 * @property int $id
 * @property int $user_id
 * @property int $redbark_feed_id
 * @property RefreshTrigger $trigger
 * @property RefreshStatus $status
 * @property int|null $accounts_synced
 * @property int|null $transactions_created
 * @property int|null $transactions_updated
 * @property int|null $balances_updated
 * @property list<array{context: string, message: string}>|null $errors
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class RedbarkSyncLog extends Model
{
    /** @use HasFactory<RedbarkSyncLogFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'redbark_feed_id',
        'trigger',
        'status',
        'accounts_synced',
        'transactions_created',
        'transactions_updated',
        'balances_updated',
        'errors',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<RedbarkFeed, $this> */
    public function feed(): BelongsTo
    {
        return $this->belongsTo(RedbarkFeed::class, 'redbark_feed_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => RefreshTrigger::class,
            'status' => RefreshStatus::class,
            'errors' => 'array',
        ];
    }
}
