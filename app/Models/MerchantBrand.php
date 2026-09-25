<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MerchantBrandStatus;
use Carbon\CarbonImmutable;
use Database\Factories\MerchantBrandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Context.dev brand resolved for one of a user's merchant keys.
 *
 * Never joined into merchant_key derivation: this is a display layer only.
 *
 * @property int $id
 * @property int $user_id
 * @property string $merchant_key
 * @property MerchantBrandStatus $status
 * @property string|null $title
 * @property string|null $domain
 * @property string|null $logo_url
 * @property string|null $industry
 * @property string|null $subindustry
 * @property bool $partial
 * @property string|null $source_descriptor
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $retry_after
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class MerchantBrand extends Model
{
    /** @use HasFactory<MerchantBrandFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'merchant_key',
        'status',
        'title',
        'domain',
        'logo_url',
        'industry',
        'subindustry',
        'partial',
        'source_descriptor',
        'resolved_at',
        'retry_after',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether a new lookup for this key is currently pointless: vetoed forever, or
     * still inside its retry window.
     */
    public function blocksLookup(): bool
    {
        return $this->status === MerchantBrandStatus::Vetoed
            || ($this->retry_after !== null && $this->retry_after->isFuture());
    }

    protected function casts(): array
    {
        return [
            'status' => MerchantBrandStatus::class,
            'partial' => 'boolean',
            'resolved_at' => 'datetime',
            'retry_after' => 'datetime',
        ];
    }
}
