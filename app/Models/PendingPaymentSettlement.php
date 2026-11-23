<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A transaction whose occurrence payments still have to be settled after a batch changed, deleted or
 * restored it. Written inside the batch's own database transaction, so a rollback discards it, and deleted in
 * the transaction that settles it. It carries no user: the transaction does.
 *
 * @property int $transaction_id
 * @property bool $restored
 * @property bool $link_changed
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PendingPaymentSettlement extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'transaction_id';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'transaction_id',
        'restored',
        'link_changed',
    ];

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
            'restored' => 'boolean',
            'link_changed' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
