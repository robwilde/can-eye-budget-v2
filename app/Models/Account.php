<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\AccountClass;
use App\Enums\AccountGroup;
use App\Enums\AccountStatus;
use App\Enums\ImportSource;
use App\Enums\StatementReconciliationStatus;
use App\Support\Redbark\InitialSyncWindow;
use Carbon\CarbonImmutable;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $user_id
 * @property ImportSource $import_source
 * @property string $name
 * @property string|null $account_last4
 * @property AccountClass $type
 * @property string $institution
 * @property string $currency
 * @property int $balance
 * @property ImportSource|null $balance_source
 * @property CarbonImmutable|null $balance_updated_at
 * @property int|null $credit_limit
 * @property int|null $available_funds
 * @property string|null $description
 * @property array<string, string>|null $column_mapping
 * @property AccountGroup $group
 * @property AccountStatus $status
 * @property bool $is_tracked
 * @property CarbonImmutable|null $reconciled_on
 * @property int|null $reconcile_difference
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'import_source',
        'name',
        'account_last4',
        'type',
        'institution',
        'currency',
        'balance',
        'balance_source',
        'balance_updated_at',
        'credit_limit',
        'available_funds',
        'description',
        'column_mapping',
        'group',
        'status',
        'is_tracked',
        'reconciled_on',
        'reconcile_difference',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return HasMany<BankImport, $this> */
    public function bankImports(): HasMany
    {
        return $this->hasMany(BankImport::class);
    }

    /** @return HasMany<StatementReconciliation, $this> */
    public function statementReconciliations(): HasMany
    {
        return $this->hasMany(StatementReconciliation::class);
    }

    /** @return HasOne<RedbarkAccount, $this> */
    public function redbarkAccount(): HasOne
    {
        return $this->hasOne(RedbarkAccount::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [AccountStatus::Active, AccountStatus::Available]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCsvImport(Builder $query): Builder
    {
        return $query->where('import_source', ImportSource::Csv);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRedbarkConnected(Builder $query): Builder
    {
        return $query->where('import_source', ImportSource::Redbark);
    }

    public function isImportSource(ImportSource $source): bool
    {
        return $this->import_source === $source;
    }

    public function acceptsCsvImports(): bool
    {
        return $this->import_source === ImportSource::Csv
            || $this->import_source === ImportSource::Manual;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('group', '!=', AccountGroup::Hidden);
    }

    /**
     * Tracked accounts feed the ledger, calendar, reports and balances. Untracked
     * ("hidden") accounts only exist as the counterpart of an external transfer and
     * carry a manually entered balance.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeTracked(Builder $query): Builder
    {
        return $query->where('is_tracked', true);
    }

    /**
     * Manual-only balance: never touched by linked transfers. A monthly reconcile
     * records the date and the difference between the old and the newly entered balance.
     */
    public function reconcileManualBalance(int $newBalance, ?CarbonImmutable $on = null): void
    {
        $difference = $newBalance - $this->balance;

        $this->forceFill([
            'balance' => $newBalance,
            'balance_updated_at' => CarbonImmutable::now(),
            'reconciled_on' => ($on ?? CarbonImmutable::today())->toDateString(),
            'reconcile_difference' => $difference,
        ])->save();
    }

    /**
     * Eager-loads the Redbark link and only last month's statement reconciliation, so a
     * list of accounts can show its reconcile state without a query per account.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithLastMonthReconciliation(Builder $query): Builder
    {
        $periodStart = InitialSyncWindow::start(CarbonImmutable::now())->toDateString();

        return $query->with([
            'redbarkAccount',
            'statementReconciliations' => fn ($reconciliations) => $reconciliations->whereDate('period_start', $periodStart),
        ]);
    }

    /**
     * Redbark-linked accounts whose statement for last month has not been reconciled and closed.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeStatementDueForLastMonth(Builder $query): Builder
    {
        $periodStart = InitialSyncWindow::start(CarbonImmutable::now())->toDateString();

        return $query->whereHas('redbarkAccount')
            ->whereDoesntHave('statementReconciliations', fn (Builder $reconciliations): Builder => $reconciliations
                ->whereDate('period_start', $periodStart)
                ->where('status', StatementReconciliationStatus::Closed));
    }

    public function availableBalance(): int
    {
        if ($this->credit_limit !== null) {
            return $this->credit_limit + $this->balance;
        }

        return $this->balance;
    }

    public function amountOwed(): int
    {
        $isDebtAccount = $this->credit_limit !== null
            || $this->type === AccountClass::CreditCard
            || $this->type === AccountClass::Loan;

        if ($isDebtAccount) {
            return abs(min(0, $this->balance));
        }

        return 0;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithRelations(Builder $query): Builder
    {
        return $query->with(['user', 'transactions']);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountClass::class,
            'group' => AccountGroup::class,
            'status' => AccountStatus::class,
            'import_source' => ImportSource::class,
            'balance' => MoneyCast::class,
            'balance_source' => ImportSource::class,
            'balance_updated_at' => 'immutable_datetime',
            'is_tracked' => 'boolean',
            'reconciled_on' => 'immutable_date',
            'reconcile_difference' => 'integer',
            'credit_limit' => MoneyCast::class,
            'available_funds' => MoneyCast::class,
            'column_mapping' => 'array',
        ];
    }
}
