<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $user_id
 * @property int $account_id
 * @property int|null $category_id
 * @property int $amount
 * @property TransactionDirection $direction
 * @property string $description
 * @property string|null $clean_description
 * @property CarbonImmutable $post_date
 * @property CarbonImmutable|null $transaction_date
 * @property TransactionStatus $status
 * @property string|null $basiq_id
 * @property string|null $basiq_account_id
 * @property string|null $merchant_name
 * @property string|null $anzsic_code
 * @property array<string, mixed>|null $enrich_data
 * @property TransactionSource $source
 * @property int|null $transfer_pair_id
 * @property int|null $planned_transaction_id
 * @property int|null $parent_transaction_id
 * @property string|null $notes
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'account_id',
        'category_id',
        'amount',
        'direction',
        'description',
        'clean_description',
        'post_date',
        'transaction_date',
        'status',
        'basiq_id',
        'basiq_account_id',
        'merchant_name',
        'anzsic_code',
        'enrich_data',
        'source',
        'transfer_pair_id',
        'planned_transaction_id',
        'parent_transaction_id',
        'notes',
    ];

    public static function findCurrentVersion(int $id, int $userId): ?self
    {
        $current = self::query()
            ->where('user_id', $userId)
            ->find($id);

        if (! $current) {
            return null;
        }

        while (true) {
            $child = self::query()
                ->where('user_id', $userId)
                ->where('parent_transaction_id', $current->id)
                ->latest('id')
                ->first();

            if (! $child) {
                return $current;
            }

            $current = $child;
        }
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

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<PlannedTransaction, $this> */
    public function plannedTransaction(): BelongsTo
    {
        return $this->belongsTo(PlannedTransaction::class);
    }

    /** @return BelongsTo<self, $this> */
    public function transferPair(): BelongsTo
    {
        return $this->belongsTo(self::class, 'transfer_pair_id');
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_transaction_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_transaction_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereDoesntHave('children', fn (Builder $q) => $q->whereNull('deleted_at'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function createChild(array $overrides = []): self
    {
        $excluded = ['id', 'created_at', 'updated_at', 'deleted_at', 'parent_transaction_id', 'basiq_id'];

        $attributes = collect($this->getAttributes())
            ->except($excluded)
            ->toArray();

        $attributes['parent_transaction_id'] = $this->id;

        return self::query()->create(array_merge($attributes, $overrides));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithRelations(Builder $query): Builder
    {
        return $query->with(['account', 'category']);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => TransactionSource::class,
            'direction' => TransactionDirection::class,
            'status' => TransactionStatus::class,
            'amount' => MoneyCast::class,
            'post_date' => 'date',
            'transaction_date' => 'date',
            'enrich_data' => 'array',
        ];
    }
}
