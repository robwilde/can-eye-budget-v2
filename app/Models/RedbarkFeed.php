<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RedbarkFeedStatus;
use Carbon\CarbonImmutable;
use Database\Factories\RedbarkFeedFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Redbark credential per user. Named "feed" rather than "connection" because
 * Redbark's own API uses connectionId for a bank connection, which is stored on
 * RedbarkAccount::$bank_connection_id instead.
 *
 * @property int $id
 * @property int $user_id
 * @property string $api_key
 * @property RedbarkFeedStatus $status
 * @property bool $pending_account_setup
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class RedbarkFeed extends Model
{
    /** @use HasFactory<RedbarkFeedFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'api_key',
        'status',
        'pending_account_setup',
        'last_synced_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<RedbarkAccount, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(RedbarkAccount::class);
    }

    /** @return HasMany<RedbarkSyncLog, $this> */
    public function syncLogs(): HasMany
    {
        return $this->hasMany(RedbarkSyncLog::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'status' => RedbarkFeedStatus::class,
            'last_synced_at' => 'immutable_datetime',
            'pending_account_setup' => 'boolean',
        ];
    }
}
