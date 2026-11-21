<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\StatementLineKind;
use App\Enums\TransactionDirection;
use Carbon\CarbonImmutable;
use Database\Factories\PdfImportLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row read from a statement PDF, awaiting review. It is scoped to the user through its
 * import. The amount is signed cents; a date that could not be read keeps its printed text.
 *
 * @property int $id
 * @property int $pdf_import_id
 * @property CarbonImmutable|null $line_date
 * @property string $raw_date
 * @property bool $date_unreadable
 * @property int $amount
 * @property TransactionDirection $direction
 * @property string $description
 * @property StatementLineKind $kind
 * @property int|null $matched_transaction_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PdfImportLine extends Model
{
    /** @use HasFactory<PdfImportLineFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'pdf_import_id',
        'line_date',
        'raw_date',
        'date_unreadable',
        'amount',
        'direction',
        'description',
        'kind',
        'matched_transaction_id',
    ];

    /** @return BelongsTo<PdfImport, $this> */
    public function pdfImport(): BelongsTo
    {
        return $this->belongsTo(PdfImport::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function matchedTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'matched_transaction_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_date' => 'immutable_date',
            'date_unreadable' => 'boolean',
            'amount' => MoneyCast::class,
            'direction' => TransactionDirection::class,
            'kind' => StatementLineKind::class,
        ];
    }
}
