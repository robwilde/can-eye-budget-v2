<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RedbarkFeedStatus;
use App\Enums\RedbarkTransport;
use App\Exceptions\Redbark\RedbarkRequestException;
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
 * @property RedbarkTransport $transport
 * @property string|null $api_key
 * @property string|null $simplefin_access_url
 * @property RedbarkFeedStatus $status
 * @property int $auth_failure_count
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
        'transport',
        'api_key',
        'simplefin_access_url',
        'status',
        'auth_failure_count',
        'pending_account_setup',
        'last_synced_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'transport' => 'rest',
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
     * Prepares the feed for credentials of another transport. Account ids differ between
     * transports, so linked accounts would be orphaned and the switch is refused; with none
     * linked, the old transport's discovered rows and sync state are discarded.
     *
     * @throws RedbarkRequestException
     */
    public function prepareTransportSwitch(RedbarkTransport $to): void
    {
        if ($this->transport === $to) {
            return;
        }

        if ($this->accounts()->linked()->exists()) {
            throw new RedbarkRequestException('Linked accounts block switching the bank connection type', 'transport_locked');
        }

        $this->accounts()->delete();
        $this->update(['last_synced_at' => null, 'pending_account_setup' => false]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'simplefin_access_url' => 'encrypted',
            'transport' => RedbarkTransport::class,
            'status' => RedbarkFeedStatus::class,
            'auth_failure_count' => 'integer',
            'last_synced_at' => 'immutable_datetime',
            'pending_account_setup' => 'boolean',
        ];
    }
}
