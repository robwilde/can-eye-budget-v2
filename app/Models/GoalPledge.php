<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\GoalPledgeStatus;
use Carbon\CarbonImmutable;
use Database\Factories\GoalPledgeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $goal_id
 * @property int $account_id
 * @property int $amount
 * @property GoalPledgeStatus $status
 * @property CarbonImmutable $expires_at
 * @property int|null $matched_transaction_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class GoalPledge extends Model
{
    /** @use HasFactory<GoalPledgeFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'goal_id',
        'account_id',
        'amount',
        'status',
        'expires_at',
        'matched_transaction_id',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
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
            'amount' => MoneyCast::class,
            'status' => GoalPledgeStatus::class,
            'expires_at' => 'immutable_datetime',
        ];
    }
}
