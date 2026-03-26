<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use Carbon\CarbonImmutable;
use Database\Factories\PlannedTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $user_id
 * @property int $account_id
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
