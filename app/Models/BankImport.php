<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BankImportStatus;
use Carbon\CarbonImmutable;
use Database\Factories\BankImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int $account_id
 * @property string $original_filename
 * @property string $stored_path
 * @property BankImportStatus $status
 * @property int $row_count
 * @property int $imported_count
 * @property int $skipped_count
 * @property int $restored_count
 * @property string|null $error_summary
 * @property array<int, array{row: int, description: string|null, message: string}>|null $row_errors
 * @property list<array{value: string, rows: int}>|null $unresolved_categories
 * @property array<string, string>|null $column_mapping
 * @property array{categories?: array<string, int|null>, tags?: array<string, array{type: string, id?: int}|null>}|null $value_mapping
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class BankImport extends Model
{
    /** @use HasFactory<BankImportFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'account_id',
        'original_filename',
        'stored_path',
        'status',
        'row_count',
        'imported_count',
        'skipped_count',
        'restored_count',
        'error_summary',
        'row_errors',
        'unresolved_categories',
        'column_mapping',
        'value_mapping',
        'started_at',
        'completed_at',
    ];

    /** @return HasMany<BankImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(BankImportRow::class);
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

    public function isComplete(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BankImportStatus::class,
            'value_mapping' => 'array',
            'column_mapping' => 'array',
            'row_errors' => 'array',
            'unresolved_categories' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
