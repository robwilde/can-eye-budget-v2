<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionDirection;
use App\Enums\TransactionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $account_id
 * @property int|null $category_id
 * @property int $amount
 * @property TransactionDirection $direction
 * @property string $description
 * @property string|null $clean_description
 * @property CarbonImmutable $post_date
 * @property CarbonImmutable|null $transaction_date
 * @property TransactionStatus $status
 * @property string|null $basiq_id
 * @property string|null $basiq_account_id
 * @property string|null $merchant_name
 * @property string|null $anzsic_code
 * @property array<string, mixed>|null $enrich_data
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
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
        'clean_description',
        'post_date',
        'transaction_date',
        'status',
        'basiq_id',
        'basiq_account_id',
        'merchant_name',
        'anzsic_code',
        'enrich_data',
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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => TransactionDirection::class,
            'status' => TransactionStatus::class,
            'amount' => 'integer',
            'post_date' => 'date',
            'transaction_date' => 'date',
            'enrich_data' => 'array',
        ];
    }
}
