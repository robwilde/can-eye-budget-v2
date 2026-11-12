<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MerchantAliasFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps one of a user's source merchant keys onto a Merchant, so two spellings
 * resolve to one merchant without rewriting any stored merchant_key.
 *
 * @property int $id
 * @property int $user_id
 * @property string $merchant_key
 * @property int $merchant_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class MerchantAlias extends Model
{
    /** @use HasFactory<MerchantAliasFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'merchant_key',
        'merchant_id',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Merchant, $this> */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
