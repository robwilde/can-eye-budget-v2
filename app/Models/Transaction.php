<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\CategorySource;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Events\TransactionCategoryUpdated;
use App\Support\Recurring\MerchantSignature;
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
 * @property CategorySource|null $category_source
 * @property int $amount
 * @property TransactionDirection $direction
 * @property string $description
 * @property string|null $clean_description
 * @property CarbonImmutable $post_date
 * @property CarbonImmutable|null $transaction_date
 * @property TransactionStatus $status
 * @property string|null $basiq_id
 * @property string|null $basiq_account_id
 * @property string|null $redbark_id
 * @property string|null $csv_hash
 * @property string|null $merchant_name
 * @property string|null $merchant_key
 * @property string|null $anzsic_code
 * @property array<string, mixed>|null $enrich_data
 * @property TransactionSource $source
 * @property int|null $transfer_pair_id
 * @property int|null $planned_transaction_id
 * @property int|null $parent_transaction_id
 * @property int|null $folded_into_transaction_id
 * @property string|null $notes
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TransactionSplit> $splits
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
     * Bucket for rows whose description fields are all empty. An explicit
     * sentinel keeps them together under one obvious label instead of grouping
     * them under '' alongside anything else that normalises to nothing.
     */
    public const string UNKNOWN_MERCHANT_KEY = '(Unknown)';

    /**
     * Transient, per-instance: whether a category change on this model should
     * fan out to its planned group.
     *
     * A real declared property, never an attribute, so it is not persisted, not
     * fillable and not reachable from mass assignment. Writers that mean
     * "exactly this row" — the bulk apply on the transactions list — set it
     * false; TransactionCategoryUpdated still fires, PropagateTransactionCategory
     * just declines to act on it. Defaults true so every other writer keeps the
     * grouping behaviour unchanged.
     */
    public bool $propagateCategoryChange = true;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'account_id',
        'category_id',
        'category_source',
        'amount',
        'direction',
        'description',
        'clean_description',
        'post_date',
        'transaction_date',
        'status',
        'basiq_id',
        'basiq_account_id',
        'redbark_id',
        'csv_hash',
        'merchant_name',
        'merchant_key',
        'anzsic_code',
        'enrich_data',
        'source',
        'transfer_pair_id',
        'planned_transaction_id',
        'parent_transaction_id',
        'folded_into_transaction_id',
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

    /**
     * Latest post_date among bank-fed (csv/basiq) transactions for a user,
     * optionally scoped to one account. Null when no imported transactions exist.
     * SoftDeletes are excluded automatically by the default builder.
     */
    public static function lastImportedPostDate(int $userId, ?int $accountId = null): ?CarbonImmutable
    {
        $max = self::query()
            ->where('user_id', $userId)
            ->whereIn('source', TransactionSource::forAnalysis())
            ->when($accountId !== null, fn ($q) => $q->where('account_id', $accountId))
            ->max('post_date');

        return $max === null ? null : CarbonImmutable::parse((string) $max);
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

    /** @return HasMany<TransactionEmail, $this> */
    public function emails(): HasMany
    {
        return $this->hasMany(TransactionEmail::class);
    }

    /** @return HasMany<TransactionSplit, $this> */
    public function splits(): HasMany
    {
        return $this->hasMany(TransactionSplit::class)->orderBy('position')->orderBy('id');
    }

    public function isSplit(): bool
    {
        return $this->relationLoaded('splits')
            ? $this->splits->isNotEmpty()
            : $this->splits()->exists();
    }

    public function splitTotal(): int
    {
        return $this->relationLoaded('splits')
            ? (int) $this->splits->sum('amount')
            : (int) $this->splits()->sum('amount');
    }

    public function splitRemainder(): int
    {
        return (int) $this->amount - $this->splitTotal();
    }

    /** @return BelongsTo<self, $this> */
    public function foldedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'folded_into_transaction_id');
    }

    /** @return HasMany<self, $this> */
    public function foldedFees(): HasMany
    {
        return $this->hasMany(self::class, 'folded_into_transaction_id')->withTrashed();
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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExcludingTransfers(Builder $query): Builder
    {
        return $query
            ->whereNull('transfer_pair_id')
            ->whereDoesntHave('category', function (Builder $q): void {
                $q->where('name', 'Transfer')
                    ->orWhereHas(
                        'parent',
                        fn (Builder $p): Builder => $p->where('name', 'Transfer'),
                    );
            });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function createChild(array $overrides = []): self
    {
        $excluded = ['id', 'created_at', 'updated_at', 'deleted_at', 'parent_transaction_id', 'basiq_id', 'redbark_id'];

        $attributes = collect($this->getAttributes())
            ->except($excluded)
            ->toArray();

        $attributes['parent_transaction_id'] = $this->id;

        // A caller that changes category_id is choosing a new category. Carrying the
        // parent's provenance onto that choice would let a rule's stamp survive a
        // human's edit, so drop it and let saving() re-derive it.
        if (array_key_exists('category_id', $overrides)
            && ! array_key_exists('category_source', $overrides)
            && $overrides['category_id'] !== $this->category_id) {
            unset($attributes['category_source']);
        }

        return self::query()->create(array_merge($attributes, $overrides));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithRelations(Builder $query): Builder
    {
        return $query->with(['account', 'category.parent.parent']);
    }

    /**
     * The persisted payee signature used to cluster transactions on the
     * transactions list. Derived from the most specific description the feed
     * gave us, falling back through clean_description to the raw description.
     *
     * Feeds emit empty strings as readily as nulls, so the fallback tests for
     * blankness rather than null — otherwise a merchant_name of '' would pin
     * the row to the unknown bucket while a perfectly good description sat
     * unused one field below.
     *
     * Returns UNKNOWN_MERCHANT_KEY rather than an empty string when all three
     * are blank, so descriptionless rows stay in one explicit bucket instead of
     * silently colliding under ''.
     */
    public function resolveMerchantKey(): string
    {
        foreach ([$this->merchant_name, $this->clean_description, $this->description] as $candidate) {
            if (mb_trim((string) $candidate) === '') {
                continue;
            }

            $key = MerchantSignature::for((string) $candidate);

            if ($key !== '') {
                return $key;
            }
        }

        return self::UNKNOWN_MERCHANT_KEY;
    }

    protected static function booted(): void
    {
        // Derive the merchant key on every write rather than only at ingest, so
        // edited descriptions and createChild() revisions cannot leave a stale
        // key pointing at the previous payee's cluster.
        self::saving(static function (Transaction $transaction): void {
            $sourcesChanged = $transaction->isDirty(['merchant_name', 'clean_description', 'description']);

            if ($transaction->merchant_key !== null && ! $sourcesChanged) {
                return;
            }

            $transaction->merchant_key = $transaction->resolveMerchantKey();
        });

        // Keep the provenance invariant: category_source is set exactly when
        // category_id is. Defaulting an undeclared source to Manual is the
        // conservative choice — a writer that does not say "a rule did this"
        // gets treated as a human, so rules err towards leaving it alone.
        self::saving(static function (Transaction $transaction): void {
            if ($transaction->category_id === null) {
                $transaction->category_source = null;

                return;
            }

            $transaction->category_source ??= CategorySource::Manual;
        });

        self::updated(static function (Transaction $transaction): void {
            if ($transaction->wasChanged('category_id')) {
                event(new TransactionCategoryUpdated(
                    $transaction,
                    $transaction->getOriginal('category_id'),
                    $transaction->propagateCategoryChange,
                ));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => TransactionSource::class,
            'category_source' => CategorySource::class,
            'direction' => TransactionDirection::class,
            'status' => TransactionStatus::class,
            'amount' => MoneyCast::class,
            'post_date' => 'date',
            'transaction_date' => 'date',
            'enrich_data' => 'array',
        ];
    }
}
