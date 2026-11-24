<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A transaction the user said does not pay a plan. Matching never proposes or links that pair again.
 *
 * @property int $id
 * @property int $user_id
 * @property int $planned_transaction_id
 * @property int $transaction_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PlannedMatchRejection extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'planned_transaction_id',
        'transaction_id',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<PlannedTransaction, $this> */
    public function plannedTransaction(): BelongsTo
    {
        return $this->belongsTo(PlannedTransaction::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
