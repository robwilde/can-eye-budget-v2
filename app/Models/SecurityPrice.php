<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use Carbon\CarbonImmutable;
use Database\Factories\SecurityPriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A security's closing price on a date, in integer cents of the price's currency. Global, like the
 * security.
 *
 * @property int $id
 * @property int $security_id
 * @property CarbonImmutable $date
 * @property int $price
 * @property string $currency
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class SecurityPrice extends Model
{
    /** @use HasFactory<SecurityPriceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'security_id',
        'date',
        'price',
        'currency',
    ];

    /** @return BelongsTo<Security, $this> */
    public function security(): BelongsTo
    {
        return $this->belongsTo(Security::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'price' => MoneyCast::class,
        ];
    }
}
