<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ExchangeRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Shared reference data: the rate between two currencies on one date. It has no user_id.
 *
 * @property int $id
 * @property string $from_currency
 * @property string $to_currency
 * @property CarbonImmutable $rate_date
 * @property float $rate
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ExchangeRate extends Model
{
    /** @use HasFactory<ExchangeRateFactory> */
    use HasFactory;

    public const int RATE_SCALE = 8;

    /**
     * The column is DECIMAL(18,8): ten integer digits and eight decimals.
     */
    public const float RATE_LIMIT = 10_000_000_000.0;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'from_currency',
        'to_currency',
        'rate_date',
        'rate',
    ];

    /**
     * A usable rate is finite, still above zero once rounded to the column's scale, and
     * within the column's range, so a stored rate always reads back as it was validated.
     */
    public static function isUsableRate(float $rate): bool
    {
        if (! is_finite($rate)) {
            return false;
        }

        $stored = (float) self::storedRate($rate);

        return $stored > 0 && $stored < self::RATE_LIMIT;
    }

    protected static function booted(): void
    {
        self::saving(static function (self $exchangeRate): void {
            $raw = $exchangeRate->getAttributes()['rate'] ?? null;

            if (! is_numeric($raw) || ! self::isUsableRate((float) $raw)) {
                throw ValidationException::withMessages([
                    'rate' => sprintf('The rate must be a finite number above zero and below %s.', number_format(self::RATE_LIMIT, 0, '.', '')),
                ]);
            }

            $exchangeRate->setAttribute('rate', self::storedRate((float) $raw));
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_date' => 'date:Y-m-d',
            'rate' => 'float',
        ];
    }

    private static function storedRate(float $rate): string
    {
        return number_format($rate, self::RATE_SCALE, '.', '');
    }
}
