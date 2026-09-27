<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatementReconciliationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\StatementReconciliationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One account's bank statement for one calendar month, checked line by line against
 * the transactions the feed delivered.
 *
 * @property int $id
 * @property int $user_id
 * @property int $account_id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property StatementReconciliationStatus $status
 * @property string $original_filename
 * @property string $stored_path
 * @property array<string, string|null> $column_mapping
 * @property int $statement_debit_total
 * @property int $statement_credit_total
 * @property int|null $closing_balance
 * @property int $lines_outside_period
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class StatementReconciliation extends Model
{
    /** @use HasFactory<StatementReconciliationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'account_id',
        'period_start',
        'period_end',
        'status',
        'original_filename',
        'stored_path',
        'column_mapping',
        'statement_debit_total',
        'statement_credit_total',
        'closing_balance',
        'lines_outside_period',
        'closed_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'open',
        'statement_debit_total' => 0,
        'statement_credit_total' => 0,
        'lines_outside_period' => 0,
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

    /** @return HasMany<StatementReconciliationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StatementReconciliationLine::class);
    }

    public function isOpen(): bool
    {
        return $this->status === StatementReconciliationStatus::Open;
    }

    /** Only an open reconciliation whose every line has been ticked off can close. */
    public function canClose(): bool
    {
        return $this->isOpen() && ! $this->lines()->whereNull('checked_at')->exists();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForPeriod(Builder $query, int $accountId, CarbonImmutable $periodStart): Builder
    {
        return $query->where('account_id', $accountId)
            ->whereDate('period_start', $periodStart->toDateString());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'status' => StatementReconciliationStatus::class,
            'column_mapping' => 'array',
            'statement_debit_total' => 'integer',
            'statement_credit_total' => 'integer',
            'closing_balance' => 'integer',
            'lines_outside_period' => 'integer',
            'closed_at' => 'immutable_datetime',
        ];
    }
}
