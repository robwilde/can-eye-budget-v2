<?php

declare(strict_types=1);

namespace App\Support\Insights;

use App\Casts\MoneyCast;

/**
 * Money wording for insights. AUD keeps the app's plain "$"; any other currency is
 * named by its code so an amount is never shown in the wrong denomination.
 */
final class InsightMoney
{
    public static function format(int $cents, string $currency): string
    {
        $currency = mb_strtoupper($currency);

        if ($currency === 'AUD') {
            return MoneyCast::format($cents);
        }

        return ($cents < 0 ? '-' : '').$currency.' '.mb_substr(MoneyCast::format(abs($cents)), 1);
    }
}
