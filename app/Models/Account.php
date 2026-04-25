<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\AccountClass;
use App\Enums\AccountGroup;
use App\Enums\AccountStatus;
use App\Enums\ImportSource;
use Carbon\CarbonImmutable;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $basiq_account_id
 * @property ImportSource $import_source
 * @property string $name
 * @property string|null $account_last4
 * @property AccountClass $type
 * @property string $institution
 * @property string $currency
 * @property int $balance
 * @property int|null $credit_limit
 * @property int|null $available_funds
 * @property string|null $description
 * @property array<string, string>|null $column_mapping
 * @property AccountGroup $group
 * @property AccountStatus $status
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
        'basiq_account_id',
        'import_source',
        'name',
        'account_last4',
        'type',
        'institution',
        'currency',
        'balance',
        'credit_limit',
        'available_funds',
        'description',
        'column_mapping',
        'group',
        'status',
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
    public function scopeBasiqConnected(Builder $query): Builder
    {
        return $query->where('import_source', ImportSource::Basiq);
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

    public function availableBalance(): int
    {
        if ($this->type === AccountClass::CreditCard) {
            return ($this->credit_limit ?? 0) + $this->balance;
        }

        return $this->balance;
    }

    public function amountOwed(): int
    {
        if ($this->type === AccountClass::CreditCard || $this->type === AccountClass::Loan) {
            return abs($this->balance);
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
            'credit_limit' => MoneyCast::class,
            'available_funds' => MoneyCast::class,
            'column_mapping' => 'array',
        ];
    }
}
