<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RuleEmailDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records that a transaction was emailed, or counted as already known when the rule was switched
 * on, for one rule, so no transaction is ever emailed twice for the same rule.
 *
 * @property int $id
 * @property int $user_id
 * @property int $user_rule_id
 * @property int $transaction_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class RuleEmailDelivery extends Model
{
    /** @use HasFactory<RuleEmailDeliveryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'user_rule_id',
        'transaction_id',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<UserRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(UserRule::class, 'user_rule_id');
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
