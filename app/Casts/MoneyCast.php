<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<int, int> */
final class MoneyCast implements CastsAttributes
{
    public static function format(int $cents): string
    {
        $isNegative = $cents < 0;
        $absCents = abs($cents);
        $dollars = intdiv($absCents, 100);
        $remainder = $absCents % 100;
        $formatted = '$'.number_format($dollars).'.'.mb_str_pad((string) $remainder, 2, '0', STR_PAD_LEFT);

        return $isNegative ? "-$formatted" : $formatted;
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): int
    {
        return (int) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return (int) $value;
    }
}
