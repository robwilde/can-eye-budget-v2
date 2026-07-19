<?php

declare(strict_types=1);

namespace App\Support\Transactions;

final class MerchantMatchValue
{
    public static function for(string $description): ?string
    {
        $fields = preg_split('/\s{2,}/', mb_trim($description)) ?: [];

        if (count($fields) < 2) {
            return null;
        }

        $merchant = mb_trim((string) preg_replace(
            '/^(VISA(\s+Android\s+Pay|\s+Refund)?\s*-\s*|Int Tran Fee\s*-\s*|Direct Debit\s+|Direct Credit\s+)/i',
            '',
            $fields[0],
        ));

        $merchant = mb_substr($merchant, 0, 21);

        return mb_strlen($merchant) >= 4 ? $merchant : null;
    }
}
