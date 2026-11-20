<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use Carbon\CarbonImmutable;
use Database\Factories\GoalSpendFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money spent from a goal's linked account, optionally tied to one outgoing transaction.
 *
 * @property int $id
 * @property int $goal_id
 * @property int|null $account_id
 * @property int|null $transaction_id
 * @property int $amount
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class GoalSpend extends Model
{
    /** @use HasFactory<GoalSpendFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'goal_id',
        'account_id',
        'transaction_id',
        'amount',
    ];

    /** @return BelongsTo<Goal, $this> */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
        ];
    }
}
