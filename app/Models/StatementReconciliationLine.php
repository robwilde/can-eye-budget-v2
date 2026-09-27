<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatementLineKind;
use App\Enums\StatementLineResolution;
use Carbon\CarbonImmutable;
use Database\Factories\StatementReconciliationLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A statement row (matched or statement-only) or a feed row the statement does not
 * show (feed-only). amount is signed cents, as on transactions.
 *
 * @property int $id
 * @property int $statement_reconciliation_id
 * @property int|null $transaction_id
 * @property StatementLineKind $kind
 * @property string|null $csv_hash
 * @property CarbonImmutable $post_date
 * @property int $amount
 * @property string $description
 * @property StatementLineResolution|null $resolution
 * @property string|null $note
 * @property CarbonImmutable|null $checked_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class StatementReconciliationLine extends Model
{
    /** @use HasFactory<StatementReconciliationLineFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'statement_reconciliation_id',
        'transaction_id',
        'kind',
        'csv_hash',
        'post_date',
        'amount',
        'description',
        'resolution',
        'note',
        'checked_at',
    ];

    /** @return BelongsTo<StatementReconciliation, $this> */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(StatementReconciliation::class, 'statement_reconciliation_id');
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function isChecked(): bool
    {
        return $this->checked_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => StatementLineKind::class,
            'post_date' => 'immutable_date',
            'amount' => 'integer',
            'resolution' => StatementLineResolution::class,
            'checked_at' => 'immutable_datetime',
        ];
    }
}
