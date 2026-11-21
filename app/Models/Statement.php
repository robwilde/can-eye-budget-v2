<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\StatementFileType;
use App\Enums\StatementReviewStatus;
use Carbon\CarbonImmutable;
use Database\Factories\StatementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use WeakMap;

/**
 * One uploaded statement file in the user's statement vault and what is known about it.
 * It may be linked to an account and, separately, suggested for another. The review
 * status follows the account. The stored file is purged when the row is deleted.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $account_id
 * @property int|null $suggested_account_id
 * @property string|null $suggestion_confidence
 * @property StatementReviewStatus $review_status
 * @property string $content_hash
 * @property string $original_filename
 * @property string $stored_path
 * @property string $mime_type
 * @property int $byte_size
 * @property StatementFileType $file_type
 * @property string|null $institution_hint
 * @property string|null $account_name_hint
 * @property string|null $last_four_hint
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property int|null $opening_balance
 * @property int|null $closing_balance
 * @property string|null $currency
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Statement extends Model
{
    /** @use HasFactory<StatementFactory> */
    use HasFactory;

    public const string DISK = 'local';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'account_id',
        'suggested_account_id',
        'suggestion_confidence',
        'review_status',
        'content_hash',
        'original_filename',
        'stored_path',
        'mime_type',
        'byte_size',
        'file_type',
        'institution_hint',
        'account_name_hint',
        'last_four_hint',
        'period_start',
        'period_end',
        'opening_balance',
        'closing_balance',
        'currency',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'review_status' => 'unmatched',
    ];

    /** @var WeakMap<Model, Collection<int, string>>|null */
    private static ?WeakMap $remembered = null;

    /**
     * Holds the stored paths of rows an SQL cascade is about to remove, until the owner's delete has run.
     *
     * @param  Collection<int, string>  $paths
     */
    public static function remember(Model $owner, Collection $paths): void
    {
        self::remembered()[$owner] = $paths;
    }

    public static function purgeRemembered(Model $owner): void
    {
        $paths = self::remembered()[$owner] ?? null;

        unset(self::remembered()[$owner]);

        if ($paths === null || $paths->isEmpty()) {
            return;
        }

        $owner->getConnection()->afterCommit(static function () use ($paths): void {
            Storage::disk(self::DISK)->delete($paths->all());
        });
    }

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

    /** @return BelongsTo<Account, $this> */
    public function suggestedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'suggested_account_id');
    }

    /** @return HasMany<StatementReconciliation, $this> */
    public function reconciliations(): HasMany
    {
        return $this->hasMany(StatementReconciliation::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * Having an account makes the statement linked; having none makes it unmatched,
     * unless it was rejected.
     */
    public function syncReviewStatus(): void
    {
        if ($this->account_id !== null) {
            $this->review_status = StatementReviewStatus::Linked;

            return;
        }

        if ($this->review_status !== StatementReviewStatus::Rejected) {
            $this->review_status = StatementReviewStatus::Unmatched;
        }
    }

    protected static function booted(): void
    {
        self::saving(static function (self $statement): void {
            $statement->syncReviewStatus();
        });

        self::deleted(static function (self $statement): void {
            $path = $statement->stored_path;

            $statement->getConnection()->afterCommit(static function () use ($path): void {
                Storage::disk(self::DISK)->delete($path);
            });
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'review_status' => StatementReviewStatus::class,
            'file_type' => StatementFileType::class,
            'suggestion_confidence' => 'decimal:4',
            'byte_size' => 'integer',
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'opening_balance' => MoneyCast::class,
            'closing_balance' => MoneyCast::class,
        ];
    }

    /** @return WeakMap<Model, Collection<int, string>> */
    private static function remembered(): WeakMap
    {
        return self::$remembered ??= new WeakMap;
    }
}
