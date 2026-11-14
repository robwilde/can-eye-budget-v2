<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use Carbon\CarbonImmutable;
use Database\Factories\GoalAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A linked account funding a goal; a null earmark means the whole balance.
 *
 * @property int $id
 * @property int $goal_id
 * @property int $account_id
 * @property int|null $earmark_amount
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class GoalAccount extends Model
{
    /** @use HasFactory<GoalAccountFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'goal_id',
        'account_id',
        'earmark_amount',
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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'earmark_amount' => MoneyCast::class,
        ];
    }
}
