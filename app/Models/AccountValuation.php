<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\ValuationKind;
use Carbon\CarbonImmutable;
use Database\Factories\AccountValuationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An absolute balance an account had on a date. It is never income or expense.
 *
 * @property int $id
 * @property int $account_id
 * @property ValuationKind $kind
 * @property CarbonImmutable $valued_on
 * @property int $balance
 * @property string|null $notes
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class AccountValuation extends Model
{
    /** @use HasFactory<AccountValuationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'kind',
        'valued_on',
        'balance',
        'notes',
    ];

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Valuations carry no user_id; ownership comes from the parent account (C2).
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
            'kind' => ValuationKind::class,
            'valued_on' => 'immutable_date',
            'balance' => MoneyCast::class,
        ];
    }
}
