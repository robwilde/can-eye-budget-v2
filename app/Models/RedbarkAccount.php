<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use Carbon\CarbonImmutable;
use Database\Factories\RedbarkAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An account as Redbark sees it, plus the link to the app account it feeds.
 *
 * @property int $id
 * @property int $redbark_feed_id
 * @property int|null $account_id
 * @property string $redbark_account_id
 * @property string|null $bank_connection_id
 * @property string $name
 * @property string|null $account_number
 * @property string $currency
 * @property int|null $current_balance
 * @property string|null $account_type
 * @property string|null $institution_name
 * @property bool $ignored
 * @property CarbonImmutable|null $sync_start_date
 * @property array<int, array<string, mixed>>|null $raw_transactions_payload
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class RedbarkAccount extends Model
{
    /** @use HasFactory<RedbarkAccountFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'redbark_feed_id',
        'account_id',
        'redbark_account_id',
        'bank_connection_id',
        'name',
        'account_number',
        'currency',
        'current_balance',
        'account_type',
        'institution_name',
        'ignored',
        'sync_start_date',
        'raw_transactions_payload',
    ];

    /** @return BelongsTo<RedbarkFeed, $this> */
    public function feed(): BelongsTo
    {
        return $this->belongsTo(RedbarkFeed::class, 'redbark_feed_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLinked(Builder $query): Builder
    {
        return $query->whereNotNull('account_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnlinked(Builder $query): Builder
    {
        return $query->whereNull('account_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNeedsSetup(Builder $query): Builder
    {
        return $query->whereNull('account_id')->where('ignored', false);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_balance' => MoneyCast::class,
            'raw_transactions_payload' => 'array',
            'sync_start_date' => 'immutable_date',
            'ignored' => 'boolean',
        ];
    }
}
