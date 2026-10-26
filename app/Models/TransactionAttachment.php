<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TransactionAttachmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use WeakMap;

/**
 * A receipt file (image or PDF) attached to a transaction. The file lives on a private disk;
 * the row follows the current version of its transaction.
 *
 * @property int $id
 * @property int $transaction_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class TransactionAttachment extends Model
{
    /** @use HasFactory<TransactionAttachmentFactory> */
    use HasFactory;

    public const int MAX_PER_TRANSACTION = 10;

    public const int MAX_KILOBYTES = 10240;

    public const string MIMES = 'jpg,jpeg,png,gif,webp,heic,pdf';

    public const string DISK = 'local';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'transaction_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
    ];

    /** @var WeakMap<Model, Collection<int, self>>|null */
    private static ?WeakMap $remembered = null;

    /**
     * Holds the files of rows an SQL cascade is about to remove, until the owner's delete has run.
     *
     * @param  Collection<int, self>  $attachments
     */
    public static function remember(Model $owner, Collection $attachments): void
    {
        self::remembered()[$owner] = $attachments;
    }

    public static function purgeRemembered(Model $owner): void
    {
        $attachments = self::remembered()[$owner] ?? null;

        unset(self::remembered()[$owner]);

        if ($attachments === null || $attachments->isEmpty()) {
            return;
        }

        $owner->getConnection()->afterCommit(static function () use ($attachments): void {
            foreach ($attachments as $attachment) {
                Storage::disk($attachment->disk)->delete($attachment->path);
            }
        });
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Attachments carry no user_id; ownership comes from the parent transaction (C2).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->whereIn(
            'transaction_id',
            Transaction::query()
                ->withTrashed()
                ->where('user_id', $user instanceof User ? $user->id : $user)
                ->select('id'),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    /** @return WeakMap<Model, Collection<int, self>> */
    private static function remembered(): WeakMap
    {
        return self::$remembered ??= new WeakMap;
    }
}
