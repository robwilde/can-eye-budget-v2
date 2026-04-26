<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Events\PlannedTransactionCategoryUpdated;
use Carbon\CarbonImmutable;
use Database\Factories\PlannedTransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $user_id
 * @property int $account_id
 * @property int|null $transfer_to_account_id
 * @property int|null $category_id
 * @property int $amount
 * @property TransactionDirection $direction
 * @property string $description
 * @property CarbonImmutable $start_date
 * @property RecurrenceFrequency $frequency
 * @property CarbonImmutable|null $until_date
 * @property bool $is_active
 * @property CarbonImmutable|null $last_generated_date
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PlannedTransaction extends Model
{
    /** @use HasFactory<PlannedTransactionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'account_id',
        'transfer_to_account_id',
        'category_id',
        'amount',
        'direction',
        'description',
        'start_date',
        'frequency',
        'until_date',
        'is_active',
        'last_generated_date',
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

    /** @return BelongsTo<Account, $this> */
    public function transferToAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'transfer_to_account_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExcludingTransfers(Builder $query): Builder
    {
        return $query->whereDoesntHave('category', function (Builder $q): void {
            $q->where('name', 'Transfer')
                ->orWhereHas(
                    'parent',
                    fn (Builder $p): Builder => $p->where('name', 'Transfer'),
                );
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        $today = CarbonImmutable::today();

        return $query
            ->where('is_active', true)
            ->where(function (Builder $q) use ($today): void {
                $q->whereNull('until_date')->orWhere('until_date', '>=', $today);
            })
            ->orderBy('start_date');
    }

    /** @return Collection<int, CarbonImmutable> */
    public function occurrencesBetween(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        if (! $this->is_active) {
            return collect();
        }

        $dates = collect();
        $current = $this->start_date;
        $limit = $this->until_date;
        $maxIterations = 1000;

        if ($this->frequency === RecurrenceFrequency::DontRepeat) {
            if ($current->between($start, $end) && ($limit === null || $current->lte($limit))) {
                $dates->push($current);
            }

            return $dates;
        }

        for ($i = 0; $i < $maxIterations; $i++) {
            if ($current->greaterThan($end)) {
                break;
            }

            if ($limit !== null && $current->greaterThan($limit)) {
                break;
            }

            if ($current->between($start, $end)) {
                $dates->push($current);
            }

            $next = $this->frequency->nextOccurrence($current);

            if ($next === null) {
                break;
            }

            $current = $next;
        }

        return $dates;
    }

    protected static function booted(): void
    {
        self::updated(static function (PlannedTransaction $plannedTransaction): void {
            if ($plannedTransaction->wasChanged('category_id')) {
                event(new PlannedTransactionCategoryUpdated(
                    $plannedTransaction,
                    $plannedTransaction->getOriginal('category_id'),
                ));
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'direction' => TransactionDirection::class,
            'frequency' => RecurrenceFrequency::class,
            'amount' => MoneyCast::class,
            'start_date' => 'date',
            'until_date' => 'date',
            'last_generated_date' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
