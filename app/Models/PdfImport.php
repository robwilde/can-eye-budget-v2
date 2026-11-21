<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PdfImportStatus;
use App\Enums\StatementDocumentType;
use Carbon\CarbonImmutable;
use Database\Factories\PdfImportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One AI extraction of a statement PDF held in the vault. Deleting an import keeps the
 * statement and its file.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $statement_id
 * @property int|null $account_id
 * @property PdfImportStatus $status
 * @property StatementDocumentType|null $document_type
 * @property string|null $date_format
 * @property string|null $failure_message
 * @property array<string, mixed>|null $summary
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PdfImport extends Model
{
    /** @use HasFactory<PdfImportFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'statement_id',
        'account_id',
        'status',
        'document_type',
        'date_format',
        'failure_message',
        'summary',
        'started_at',
        'completed_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Statement, $this> */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(Statement::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return HasMany<PdfImportLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PdfImportLine::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PdfImportStatus::class,
            'document_type' => StatementDocumentType::class,
            'summary' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
