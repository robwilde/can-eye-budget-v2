<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BankImportStatus;
use Carbon\CarbonImmutable;
use Database\Factories\BankImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property string|null $error_summary
 * @property array<string, string>|null $column_mapping
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
        'error_summary',
        'column_mapping',
        'started_at',
        'completed_at',
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
            'column_mapping' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
