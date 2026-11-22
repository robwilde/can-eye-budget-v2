<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DataExportStatus;
use Carbon\CarbonImmutable;
use Database\Factories\DataExportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A user's request for an archive of their data.
 *
 * @property int $id
 * @property int $user_id
 * @property DataExportStatus $status
 * @property string|null $stored_path
 * @property int|null $byte_size
 * @property string|null $failure_message
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class DataExport extends Model
{
    /** @use HasFactory<DataExportFactory> */
    use HasFactory;

    public const int STUCK_AFTER_HOURS = 2;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'status',
        'stored_path',
        'byte_size',
        'failure_message',
        'started_at',
        'completed_at',
    ];

    /**
     * Fails every export that has been processing for longer than STUCK_AFTER_HOURS.
     *
     * @return int how many were failed
     */
    public static function failStuck(): int
    {
        return self::query()
            ->where('status', DataExportStatus::Processing)
            ->where('started_at', '<', now()->subHours(self::STUCK_AFTER_HOURS))
            ->update([
                'status' => DataExportStatus::Failed,
                'failure_message' => 'The export did not finish in time.',
                'completed_at' => now(),
            ]);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        self::deleting(function (self $export): void {
            if ($export->stored_path !== null) {
                Storage::disk('local')->delete($export->stored_path);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DataExportStatus::class,
            'byte_size' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
