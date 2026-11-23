<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use JsonException;

/**
 * A plan's days of the month as a sorted, de-duplicated list: whole days 1 to 31 in order, then 'last'.
 * Anything else is kept after them so the model's saving rule can refuse it.
 *
 * @implements CastsAttributes<list<mixed>|null, mixed>
 */
final class DaysOfMonthCast implements CastsAttributes
{
    public const string LAST = 'last';

    /**
     * @param  array<mixed>  $values
     * @return list<mixed>
     */
    public static function normalise(array $values): array
    {
        $days = [];
        $last = false;
        $other = [];

        foreach ($values as $value) {
            if (is_string($value) && mb_strtolower(mb_trim($value)) === self::LAST) {
                $last = true;
            } elseif (is_int($value) || (is_string($value) && preg_match('/^\d{1,3}$/', mb_trim($value)) === 1)) {
                $days[(int) $value] = (int) $value;
            } else {
                $other[] = $value;
            }
        }

        ksort($days);

        return [...array_values($days), ...($last ? [self::LAST] : []), ...$other];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $days = is_array($decoded) ? self::normalise($decoded) : [];

        return $days === [] ? null : $days;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }

        $days = self::normalise(is_array($value) ? $value : [$value]);

        return $days === [] ? null : json_encode($days, JSON_THROW_ON_ERROR);
    }
}
