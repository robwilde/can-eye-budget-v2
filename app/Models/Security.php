<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SecurityFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tradable instrument. Securities are global, shared by every user like the price history that
 * hangs off them; what a user owns of one is reached through their accounts' holdings and trades.
 * Ticker and exchange are stored upper-cased, so the unique index holds whatever case came in; no
 * exchange is the empty string, so the index also holds for those.
 *
 * @property int $id
 * @property string $ticker
 * @property string $exchange_mic
 * @property string $name
 * @property string $currency
 * @property string|null $provider
 * @property string|null $offline_reason
 * @property CarbonImmutable|null $offline_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Security extends Model
{
    /** @use HasFactory<SecurityFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ticker',
        'exchange_mic',
        'name',
        'currency',
        'provider',
        'offline_reason',
        'offline_at',
    ];

    /**
     * The stored form of a ticker or an exchange code: trimmed and upper-cased. Code that writes
     * securities or looks them up without going through the model (an upsert, a where clause) uses
     * this so it agrees with the unique index on SQLite as well as MariaDB.
     */
    public static function normalise(string $value): string
    {
        return mb_strtoupper(mb_trim($value));
    }

    /** @return HasMany<SecurityPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(SecurityPrice::class);
    }

    /** @return HasMany<SecuritySplit, $this> */
    public function splits(): HasMany
    {
        return $this->hasMany(SecuritySplit::class);
    }

    /**
     * @return Attribute<string, string|null>
     */
    protected function ticker(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => $value === null ? null : self::normalise($value));
    }

    /**
     * @return Attribute<string, string|null>
     */
    protected function exchangeMic(): Attribute
    {
        return Attribute::make(set: fn (?string $value): string => self::normalise((string) $value));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'offline_at' => 'immutable_datetime',
        ];
    }
}
