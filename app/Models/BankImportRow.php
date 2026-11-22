<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionDirection;
use Carbon\CarbonImmutable;
use Database\Factories\BankImportRowFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One parsed row of a CSV import, editable until the import runs. It is scoped to the user
 * through its import. Amounts are signed cents; a cell that failed validation keeps its
 * printed text and is named in errors.
 *
 * @property int $id
 * @property int $bank_import_id
 * @property int $row_number
 * @property CarbonImmutable|null $date
 * @property string|null $raw_date
 * @property int|null $amount
 * @property string|null $raw_amount
 * @property TransactionDirection|null $direction
 * @property string|null $description
 * @property string|null $category_value
 * @property string|null $tag_value
 * @property array<string, string>|null $errors
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class BankImportRow extends Model
{
    /** @use HasFactory<BankImportRowFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'bank_import_id',
        'row_number',
        'date',
        'raw_date',
        'amount',
        'raw_amount',
        'direction',
        'description',
        'category_value',
        'tag_value',
        'errors',
    ];

    /** @return BelongsTo<BankImport, $this> */
    public function bankImport(): BelongsTo
    {
        return $this->belongsTo(BankImport::class);
    }

    public function isValid(): bool
    {
        return $this->errors === null || $this->errors === [];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->whereIn('bank_import_id', BankImport::query()->where('user_id', $user instanceof User ? $user->id : $user)->select('id'));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInvalid(Builder $query): Builder
    {
        return $query->whereNotNull('errors');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'amount' => 'integer',
            'direction' => TransactionDirection::class,
            'errors' => 'array',
        ];
    }
}
