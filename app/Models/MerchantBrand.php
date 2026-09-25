<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MerchantBrandStatus;
use Carbon\CarbonImmutable;
use Database\Factories\MerchantBrandFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The outcome of a Context.dev brand lookup for one of a user's merchant keys:
 * resolved, unresolved, or vetoed by the user (a veto may exist with no lookup
 * ever made).
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

    /**
     * @var list<string>
     */
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

    /**
     * The query form of blocksLookup(): vetoed, or still inside the retry window.
     *
     * @param  Builder<MerchantBrand>  $query
     * @return Builder<MerchantBrand>
     */
    public function scopeBlockingLookup(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('status', MerchantBrandStatus::Vetoed)
            ->orWhere('retry_after', '>', now()));
    }

    /**
     * @return array<string, string>
     */
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
