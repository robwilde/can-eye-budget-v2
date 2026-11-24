<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record that one bill alert was sent: a subscription audit per occurrence, a cash-flow warning per day. The
 * unique key is what stops a rerun from sending it twice.
 *
 * @property int $id
 * @property int $user_id
 * @property string $kind
 * @property string $dedupe_key
 * @property CarbonImmutable $created_at
 */
final class BillAlertSend extends Model
{
    public const string SUBSCRIPTION_AUDIT = 'subscription_audit';

    public const string CASH_FLOW = 'cash_flow';

    protected $fillable = ['user_id', 'kind', 'dedupe_key'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
