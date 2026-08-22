<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Support\RedbarkCurrency;
use Spatie\LaravelData\Dto;

/**
 * A row from GET /v1/balances. Balances are decimal strings; the job converts them to
 * cents and never writes a balance it could not parse.
 */
final class RedbarkBalanceData extends Dto
{
    public function __construct(
        public readonly string $accountId = '',
        public readonly ?string $currentBalance = null,
        public readonly ?string $availableBalance = null,
        public readonly ?string $currency = null,
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public static function prepareForPipeline(array $properties): array
    {
        if (array_key_exists('accountId', $properties) && $properties['accountId'] === null) {
            $properties['accountId'] = '';
        }

        $properties['currency'] = RedbarkCurrency::normalise($properties['currency'] ?? null);

        return $properties;
    }
}
