<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Remembers a confirmed transfer: a row on $account_id whose description contains
 * $description_pattern is a transfer with $counterpart_account_id.
 *
 * @property int $id
 * @property int $user_id
 * @property int $account_id
 * @property int $counterpart_account_id
 * @property string $description_pattern
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class TransferRule extends Model
{
    /** Words that carry no identity on their own: a head made only of these matches unrelated rows. */
    private const array GENERIC_WORDS = ['transfer', 'to', 'from', 'xfer', 'in', 'out', 'internal', 'funds', 'between', 'account', 'payment'];

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'account_id',
        'counterpart_account_id',
        'description_pattern',
    ];

    /**
     * The stable head of a bank description: everything before the first token carrying a
     * reference number ("Transfer Optimus to CC from SAV xxxx5066 MOBILE#2532147918" ->
     * "Transfer Optimus to CC from SAV xxxx5066"), so the rule matches the next import, whose
     * reference differs. A masked account token (xx1234, xxxx5066) is stable and is kept: it
     * is the discriminating part of the description. Falls back to the whole description.
     *
     * Returns '' when the head is only generic words ("Transfer to"): such a rule would
     * match every transfer-like row on the account, so none is remembered.
     */
    public static function patternFor(string $description): string
    {
        $description = mb_trim($description);
        $stable = [];

        foreach (preg_split('/\s+/u', $description, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (preg_match('/^x+\d{3,4}:?$/i', $token) !== 1 && preg_match('/\d{4,}/', $token) === 1) {
                break;
            }

            $stable[] = $token;
        }

        $head = mb_substr($stable === [] ? $description : implode(' ', $stable), 0, 255);

        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($head), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_diff($words, self::GENERIC_WORDS) === [] ? '' : $head;
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function counterpartAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counterpart_account_id');
    }

    public function matches(Transaction $transaction): bool
    {
        return $transaction->account_id === $this->account_id
            && mb_stripos($transaction->description, $this->description_pattern) !== false;
    }
}
