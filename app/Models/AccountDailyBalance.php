<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use Carbon\CarbonImmutable;
use Database\Factories\AccountDailyBalanceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account's cash balance over one day, derived from its transactions and valuations.
 * Rows are rebuilt by AccountBalanceHistoryBuilder and never edited directly.
 *
 * @property int $id
 * @property int $account_id
 * @property CarbonImmutable $balance_date
 * @property int $start_balance
 * @property int $end_balance
 * @property int $inflows
 * @property int $outflows
 * @property int $adjustments a valuation's correction: its balance minus start + inflows - outflows
 * @property string $currency
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class AccountDailyBalance extends Model
{
    /** @use HasFactory<AccountDailyBalanceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'balance_date',
        'start_balance',
        'end_balance',
        'inflows',
        'outflows',
        'adjustments',
        'currency',
    ];

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Daily balances carry no user_id; ownership comes from the parent account (C2).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->whereIn(
            'account_id',
            Account::query()
                ->where('user_id', $user instanceof User ? $user->id : $user)
                ->select('id'),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance_date' => 'immutable_date',
            'start_balance' => MoneyCast::class,
            'end_balance' => MoneyCast::class,
            'inflows' => MoneyCast::class,
            'outflows' => MoneyCast::class,
            'adjustments' => MoneyCast::class,
        ];
    }
}
